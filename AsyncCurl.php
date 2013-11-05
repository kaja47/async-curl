<?php

namespace Atrox;

use React\Promise\Deferred;


trait CurlLike {
  use FuncLike;

  // requirement of trait FuncLike 
  protected function makeFunc($f) { return new MappedCurl($f, $this); }

  abstract function run($curl);

  function __invoke($curl) { return $this->run($curl); }
 
  function request($method, $url, $args = [], $cfg = []) {
    $cfg = Curl::makeCfgFunc($cfg);
    return $this->run($cfg(Curl::makeCurl($method, $url, $args)));
  }


  function get($url, $args = [], $cfg = []) { return $this->request('get', $url, $args, $cfg); }
}


class MappedCurl {
  use CurlLike;

  private $run, $curl;

  // requirement of trait FuncLike 
  protected function func() { return $this->run; }

  function __construct($run, $curl) {
    list($this->run, $this->curl) = func_get_args();
  }

  function run($curl) {
    return call_user_func($this->run, $curl);
  }

  function loop() {
    return $this->curl->loop();
  }
}


class Curl {
  use CurlLike;

  private $multi;
  private $tasks = array(); // handle id => [url, callback]

  function __construct() {
    $this->multi = curl_multi_init();
  }

  function __destruct() {
    curl_multi_close($this->multi);
  }

  // *** static constructors ***

  static function rawCallbacks() {
    return new Curl();
  }

  static function callbacks() {
    $cfg = self::makeCfgFunc([CURLOPT_HEADER => true]);
    $res = function ($q) {
      return function ($cb) use ($q) {
        return $q(function ($ok, $err) use ($cb) {
          $cb($err === null ? Curl::parseResponse($ok) : $ok, $err);
        });
      };
    };
    return self::rawCallbacks()->compose($cfg)->andThen($res);
  }

  static function rawPromises() {
    return self::rawCallbacks()->andThen('Curl::callbackToPromise');
  }

  static function promises() {
    return self::callbacks()->andThen('Curl::callbackToPromise');
  }


  // *** meat of the class ***

  function run($curl) {
    return function ($cb) use ($curl) {
      $code = curl_multi_add_handle($this->multi, $curl);
      if ($code === 0) {
        $this->tasks[(int) $curl] = [$url, $cb];
        while (curl_multi_exec($this->multi, $running) == CURLM_CALL_MULTI_PERFORM) {}
      } else {
        curl_multi_remove_handle($this->multi, $curl);
        $cb(null, new \Exception(curl_multi_strerror($code))); // maybe should throw directly
      }
    };
  }


  // *** curl loop ***

  private function step($timeout = 0.001) {
    curl_multi_select($this->multi, $timeout);
    while(curl_multi_exec($this->multi, $running) == CURLM_CALL_MULTI_PERFORM) {}

    while ($info = curl_multi_info_read($this->multi)) {
      if ($info['msg'] == CURLMSG_DONE) {
        $code = $info['result'];
        $curl = $info['handle'];
        list($url, $cb) = $this->tasks[(int) $curl];

        if ($code === CURLE_OK) {
          $content = curl_multi_getcontent($curl);
          unset($this->tasks[(int) $curl]);
          curl_multi_remove_handle($this->multi, $curl);
          $cb(($content), null);
        } else {
          unset($this->tasks[(int) $curl]);
          curl_multi_remove_handle($this->multi, $curl);
          $cb(null, new \Exception(curl_strerror($code)));
        }

      } else {
        var_dump("This should never happen, but who em I to judge!");
        var_dump($info);
      }
    }

    return count($this->tasks);
  }

  function loop($terminate = true) {
    while (!$terminate || $this->step() !== 0);
  }


  // *** static utils ***

  static function makeCurl($method, $url, $args) {
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_ENCODING, '');
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if (strtoupper($method) === 'POST') {
      curl_setopt($curl, CURLOPT_POSTFIELDS, $args);
    } else {
      $q = http_build_query($args, '', '&', PHP_QUERY_RFC3986);
      $sep = (strpos($url, '?') === false) ? '?' : '&';
      curl_setopt($curl, CURLOPT_URL, $url.$sep.$q);
    }
    return $curl;
  }

  static function makeCfgFunc($cfg) {
    return function ($curl) use ($cfg) {
      if ($cfg instanceof \Closure) $cfg($curl);
      else curl_setopt_array($curl, $cfg);
      return $curl;
    };
  }

  // based on https://github.com/guzzle/parser/blob/master/Message/MessageParser.php
  static function parseResponse($message) {
    if (!$message)
      return null;

    $startLine = null;
    $headers = array();
    $body = '';

    // Iterate over each line in the message, accounting for line endings
    $lines = preg_split('/(\\r?\\n)/', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 0, $totalLines = count($lines); $i < $totalLines; $i += 2) {

      $line = $lines[$i];

      // If two line breaks were encountered, then this is the end of body
      if (empty($line)) {
        if ($i < $totalLines - 1) {
          $body = implode('', array_slice($lines, $i + 2));
        }
        break;
      }

      // Parse message headers
      if (!$startLine) {
        $startLine = explode(' ', $line, 3);
      } elseif (strpos($line, ':')) {
        $parts = explode(':', $line, 2);
        $key = trim($parts[0]);
        $value = isset($parts[1]) ? trim($parts[1]) : '';
        if (!isset($headers[$key])) {
          $headers[$key] = $value;
        } elseif (!is_array($headers[$key])) {
          $headers[$key] = array($headers[$key], $value);
        } else {
          $headers[$key][] = $value;
        }
      }
    }

    list($protocol, $version) = explode('/', trim($startLine[0]));
    $code = $startLine[1];
    $reasonPhrase = isset($startLine[2]) ? $startLine[2] : '';

    return new Response($protocol, $version, $code, $reasonPhrase, $headers, $body);
  }

  static function callbackToPromise($cb) {
    $def = new Deferred();
    $cb(function ($ok, $err) use ($def) {
      ($err !== null) ? $def->reject($err) : $def->resolve($ok);
    });
    return $def->promise();
  }

}


class Response {
  private $protocol, $version, $code, $reasonPhrase, $headers, $body;

  function __construct($protocol, $version, $code, $reasonPhrase, $headers, $body) {
    list($this->protocol, $this->version, $this->code, $this->reasonPhrase, $this->headers, $this->body) = func_get_args();
  }

  function __get($name) {
    return $this->$name;
  }
}