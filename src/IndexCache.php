<?php

namespace IndexCache;

class IndexCache {

    public function is_https() {
        global $server;
        return isset($server->HTTPS);
    }

    public function read_url() {
        global $server;
        $url = sprintf('https://%s%s', $server->HTTP_HOST, $server->REQUEST_URI);
        define('IS_DEV', strpos($server->HTTP_HOST, '.io') !== false);
        return $url;
    }

    public function try_cache() {

        $url = $this->read_url();

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
                header("Location: /wp-content/themes/chillhop/assets/images/noimg.png");
            }
            die;
        }

        if ($this->do_cache($path)) {
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

        include 'index.php';
    }

    public function is_china() {
        return USER_COUNTRY === 'CN';
    }

    public function set_current_country() {
        global $server;
        $country = 'US';
        if (isset($server->HTTP_CF_IPCOUNTRY)) {
            $country = $server->HTTP_CF_IPCOUNTRY;
        }
        define('USER_COUNTRY', $country);
    }

    public function get_cache_root() {
        $folder = sprintf('%s/%s/', $_SERVER['DOCUMENT_ROOT'], 'cache');
        if (!is_dir($folder)) {
            @mkdir($folder);
        }
        return $folder;
    }

    function compress($html) {
        return preg_replace('/\s\s+/', ' ', $html);
    }
    
    /**
     * 
     * @param type $data
     * @param type $file
     */
    private function write($data, $file){
        
        $ok = file_put_contents($file, $data);
        if($ok){
            chmod($file, 0774);
        }
        
        return $ok;
        
    }

    public function set($name, $content) {
        $file = $this->get_cache_root() . $name;
        $base = dirname($file);
        if (!is_dir($base)) {
            mkdir($base);
        }
        
        return $this->write($this->compress($content), $file);
    }

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
