<?php

# Shards keys within multiple Redis servers.
# 
# =Key Hashing
# 
# For commands like SORT to work, all matching keys are expected to be in a
# single Redis server. For assigning the same type of keys to the same server
# you must specify your own hashing method.
# 
# For instance the following method will only encode the begining of the key
# (whatever is before +:+), so that all +webcomic:*+ keys will be on the same
# servers, while +chapters:*+ could be on another one:
# 
#   $redis = new RedisCluster(array($server1, $server2), function($key)
#   {
#     $hkey = explode(':', $key, 2);
#     return md5($hkey[0]);
#   });
# 
# TODO: Proper MULTI/EXEC functionality across servers.
# TODO: Support for PIPELINE across servers.
class RedisCluster
{
  private $servers;
  private $hash_method;
  
  function __construct($configs=array(), $hash_method='md5', $debug=false)
  {
    $this->servers     = new RedisServers($configs, $debug);
    $this->hash_method = $hash_method;
  }
  
  function __call($func, $args)
  {
#    if (!isset($args[0])) {
#      trigger_error('The command '.$func.' makes no sense on a cluster.', E_USER_WARNING);
#    }
    $server = isset($args[0]) ? $this->hash($func, $args[0]) : 0;
    return $this->send_command($server, $func, $args);
  }
  
  # Sends a command to a specific server.
  function send_command($server, $command, $args=array()) {
    return call_user_func_array(array($this->servers[$server], $command), $args);
  }
  
  function & mget($keys)
  {
    if (!is_array($keys)) {
      $keys = func_get_args();
    }
    
    $keys_by_server = array();
    foreach($keys as $key)
    {
      $server = $this->hash('mget', $key);
      $keys_by_server[$server][] = $key;
    }
    
    $result = array();
    foreach($keys_by_server as $server => $args)
    {
      $server_result = $this->send_command($server, 'mget', $args);
      $result = array_merge($result, $server_result);
    }
    return $result;
  }
  
  function mset($keys) {
    return $this->_mset($keys);
  }
  
  function msetnx($keys) {
    return $this->_mset($keys, true);
  }
  
  private function _mset($keys, $nx=false)
  {
    $cmd = "mset".($nx ? 'nx' : '');
    $keys_by_server = array();
    foreach($keys as $key => $value)
    {
      $server = $this->hash($cmd, $key);
      $keys_by_server[$server][$key] = $value;
    }
    
    $rs = true;
    foreach($keys_by_server as $server => $args) {
      $rs = $rs && !!$this->send_command($server, $cmd, array($args));
    }
    return $rs;
  }
  
  function pipeline() {
    trigger_error("Pipelining isn't supported yet in RedisCluster.", E_USER_ERROR);
  }
  
  private function hash($func, $key)
  {
    if (count($this->servers) > 1)
    {
      $cmd  = Redis::lookup_command($func);
      $hash = call_user_func($this->hash_method, $key);
      return $hash % count($this->servers);
    }
    return 0;
  }
}

?>
