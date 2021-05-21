<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace IndexCache;

/**
 * Description of UserLog
 *
 * @author darius
 */
class UserLog {

    const LOG_FILE = 'access_log';
    
    /**
     * 
     * @var IndexCache
     */
    private $cache;
    function __construct(IndexCache $cache) {
        $this->cache = $cache;
    }

    /**
     * Logs an access entry
     * 
     * @param IndexCache $cache
     */
    function log_access() {
        $line = sprintf('%s%s:%s', PHP_EOL, time(), $this->cache->get_user_ip());
        $this->cache->append(static::LOG_FILE, $line);
    }
    
    /**
     * Returns the amount of time an IP has accessed in the last x seconds
     * 
     * @param type $time
     */
    function access_count($seconds = 5){
        
        $lines = $this->cache->get(static::LOG_FILE, 999999999);
        if(!$lines){
            return 0;
        }
        
        $ip = $this->cache->get_user_ip();
        $curtime = time() - $seconds;
        
        $times = 0;
        $exploded = explode(PHP_EOL, $lines);
        foreach($exploded as $line){
            $ps = explode(':', $line);
            $time = floatval($ps[0]);
            $ipq = $ps[1];
            if($ipq === $ip && $curtime > $time){
                $times++;
            }
        }
        
        return $times;
    }

}
