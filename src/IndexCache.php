<?php

namespace IndexCache;

class IndexCache {

    /**
     * When the request is an image, it means that there is a 404. 
     * We want to display a dummy image
     * 
     * @var type
     */
    public $noimg = '';

    /**
     * Current root of this package
     * 
     * @var type
     */
    public $path = '';

    /**
     * All global server variables
     * @var type
     */
    public $server = '';

    /**
     * Current URL
     * 
     * @var type
     */
    public $host = '';
    
    /**
     * File that will be loaded to generate the cache
     * 
     * @var type
     */
    public $indexFile = '';

    function __construct($indexFile = 'index.php') {

        if (!is_file($indexFile)) {
            throw new Exception('No index file found at ' . $indexFile);
        }
        
        $this->path = dirname(dirname(__FILE__)); // ../src/IndexCacheTests.php
        $this->noimg = sprintf('%s/assets/images/noimg.jpg', $this->path);
        $this->server = (object) $_SERVER;
        $this->host = sprintf('%s://%s', $this->server->HTTP_HOST, !$this->is_https() ? 'http' : 'https');
        $this->indexFile = $indexFile;
        
        if (!defined('IS_DEV')) {
            define('IS_DEV', strpos($this->host, '.io') !== false);
        }
        
        $this->set_current_country();
        $this->get_cache_root();
    }

    /**
     * Checks if the current URL has https enabled
     * 
     * @return type
     */
    public function is_https() {
        return isset($this->server->HTTPS);
    }

    /**
     * Gets the current URL
     * 
     * @return type
     */
    public function get_url() {
        $url = sprintf('%s%s', $this->host, $this->server->REQUEST_URI);
        return $url;
    }

    /**
     * Will attempt to cache the current URL, will also redirect if not
     * in IS_DEV mode to HTTPS
     */
    public function try_cache() {

        $url = $this->get_url();

        if (!IS_DEV && !$this->is_https()) {
            header("Status: 301 Moved Permanently");
            header(sprintf("Location: %s", $url));
            die();
        }

        $url_parts = (Object) parse_url($url);

        $path = $url_parts->path;
        if ($this->check_if_media($path)) {
            /**
             * This is should be a 404
             */
            if (defined('IS_DEV')) {
                header("Location: https://chillhop.com" . $path);
            } else {
                header("Location: " . $this->noimg);
            }
            die();
        }

        if (!$this->do_cache($path)) {
            include 'index.php';
            return;
        }

        $filename = md5($url) . '-' . ($this->is_china() ? 'CN' : 'WORLD');
        $data = $this->get($filename, 3600 * 24);
        if ($data) {
            echo $data;
            die();
        }
        ob_start();
        include 'index.php';
        $content = ob_get_clean();
        if (strpos($content, '<html') !== false && strpos($content, 'error404') === false) {
            $this->set($filename, $content);
        }
        echo $content;
        die();
    }

    /**
     * If we are in China
     * 
     * @return type
     */
    public function is_china() {
        return USER_COUNTRY === 'CN';
    }

    /**
     * If using Cloudflare we can grab the country and 
     * store it in a local variable
     */
    public function set_current_country() {

        if (defined('USER_COUNTRY')) {
            return USER_COUNTRY;
        }

        $country = 'US';
        if (isset($this->server->HTTP_CF_IPCOUNTRY)) {
            $country = $this->server->HTTP_CF_IPCOUNTRY;
        }

        define('USER_COUNTRY', $country);
        return $country;
    }

    /**
     * cache will be saved in ROOT/cache/
     * 
     * @return type
     */
    public function get_cache_root() {
        $folder = sprintf('%s/%s/', $this->path, 'cache');
        if (!is_dir($folder)) {
            @mkdir($folder);
        }
        return $folder;
    }

    /**
     * Compresses HTML
     * 
     * @param type $html
     * @return type
     */
    function compress($html) {
        return preg_replace('/\s\s+/', ' ', $html);
    }

    /**
     * 
     * @param type $data
     * @param type $file
     */
    private function write($data, $file) {

        $ok = file_put_contents($file, $data);
        if ($ok) {
            chmod($file, 0774);
        }

        return $ok;
    }

    /**
     * Add a cache file
     * 
     * @param type $name
     * @param type $content
     * @return type
     */
    public function set($name, $content) {
        $file = $this->get_cache_root() . $name;
        $base = dirname($file);
        if (!is_dir($base)) {
            mkdir($base);
        }

        return $this->write($this->compress($content), $file);
    }

    /**
     * Gets a cache file
     * 
     * @param type $name
     * @param type $max_life
     * @return boolean
     */
    public function get($name, $max_life = 3600) {
        $file = $this->get_cache_root() . $name;
        if (is_file($file)) {
            $ctime = filectime($file);
            if ($ctime < time() - $max_life) {
                return false;
            }
            return file_get_contents($file);
        }
        return false;
    }

    /**
     * 
     * @param type $name
     * @param type $json
     * @param type $expire_on_date
     * @return type
     */
    public function set_json($name, $json, $expire_on_date = false) {
        $file = sprintf('%s%s-JSON', $this->get_cache_root(), $name);
        $data = (Object) [];
        $data->expires = !$expire_on_date ? time() + 10 : $expire_on_date;
        $data->json = $json;
        return $this->write(json_encode($data), $file);
    }

    /**
     * 
     * @param type $name
     * @return boolean
     */
    public function get_json($name) {
        $file = sprintf('%s%s-JSON', $this->get_cache_root(), $name);
        if (is_file($file)) {
            $data = json_decode(file_get_contents($file));
            $expires = $data->expires;
            $json = $data->json;
            if (intval($expires) > time()) {
                return $json;
            }
        }
        return false;
    }

    public function release() {
        $file = $this->get_cache_root();
        $files = scandir($file);
        foreach ($files as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($file . $name);
            }
        }
    }

    function check_if_media($path) {
        //Omit cache for 404 images
        $type = strlen($path) - 4;
        if (strpos($path, '.jpg') === $type ||
                strpos($path, '.png') === $type ||
                strpos($path, '.gif') === $type) {
            return true;
        }
        return false;
    }

    function do_cache($path) {
        $remove_from_cache = ['/contact-us/', '/user/', '/creators/', '.xml', '/12-days-of-chillhop/'];

        foreach ($remove_from_cache as $uri) {
            if (strpos($path, $uri) !== false) {
                return false;
            }
        }

        $getpost = array_merge($_GET, $_POST);
        unset($getpost['_pjax']);
        unset($getpost['_']);
        if (count($getpost) > 0) {
            return false;
        }

        return !$this->check_if_media($path);
    }

}
