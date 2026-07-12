<?php
/**
 * Cache — one JSON file per pending login, keyed by "<app>_<CODE>".
 *
 * This is the hand-off store between the browser (which completes the OAuth /
 * xAuth flow) and the legacy device (which polls for the resulting tokens).
 * Files are short-lived: created when a device asks for a code, filled in when
 * the browser finishes authenticating, deleted the moment the device claims
 * the tokens (one-time pickup) — or reaped after CACHE_TTL if abandoned.
 *
 * Adapted from webOSArchive/instapaper-auth's Cache.php, generalized so a
 * single broker can serve many apps without code collisions.
 */
class Cache {

    private $path;
    private $ttl;

    /**
     * @param string $path Directory to store code files in (should be OUTSIDE the web root).
     * @param int    $ttl  Seconds a code file may live before it is reaped. Default 2h.
     */
    public function __construct($path, $ttl = 7200) {
        $this->path = rtrim($path, '/') . '/';
        $this->ttl  = $ttl;
    }

    private function fileFor($app, $code) {
        // App + code together form the filename; both are already restricted to
        // a safe alphabet by the callers, but re-sanitise defensively.
        $safeApp  = preg_replace('/[^a-z0-9_-]/i', '', $app);
        $safeCode = preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
        return $this->path . $safeApp . '_' . $safeCode . '.json';
    }

    /** Create a brand-new pending-login record. Returns true on success. */
    public function create($app, $code, array $extra = array()) {
        $this->reap();
        $obj = array_merge(array(
            'app'     => $app,
            'code'    => $code,
            'created' => time(),
            'status'  => 'pending',
        ), $extra);
        return $this->writeObj($app, $code, $obj);
    }

    /** True if a pending record exists for this app+code (and isn't expired). */
    public function exists($app, $code) {
        $file = $this->fileFor($app, $code);
        if (!is_file($file)) {
            return false;
        }
        if (time() - filemtime($file) > $this->ttl) {
            @unlink($file);
            return false;
        }
        return true;
    }

    /** Read the record as an associative array, or false if missing/expired. */
    public function read($app, $code) {
        if (!$this->exists($app, $code)) {
            return false;
        }
        $str = @file_get_contents($this->fileFor($app, $code));
        if ($str === false) {
            return false;
        }
        $obj = json_decode($str, true);
        return is_array($obj) ? $obj : false;
    }

    /**
     * Merge $tokens into an existing record and mark it ready for pickup.
     * Called by the browser side once authentication succeeds.
     */
    public function fulfill($app, $code, array $tokens) {
        $obj = $this->read($app, $code);
        if ($obj === false) {
            return false;
        }
        $obj = array_merge($obj, $tokens);
        $obj['status']    = 'ready';
        $obj['fulfilled'] = time();
        return $this->writeObj($app, $code, $obj);
    }

    /** Delete a record — used for one-time pickup after the device claims tokens. */
    public function remove($app, $code) {
        $file = $this->fileFor($app, $code);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function writeObj($app, $code, array $obj) {
        $file = $this->fileFor($app, $code);
        $fh = @fopen($file, 'w');
        if ($fh === false) {
            error_log('oauth-broker: cannot open cache file for write: ' . $file);
            return false;
        }
        fwrite($fh, json_encode($obj));
        fclose($fh);
        return true;
    }

    /** Delete every expired code file across all apps. */
    public function reap() {
        clearstatcache();
        $handle = @opendir($this->path);
        if ($handle === false) {
            error_log('oauth-broker: cannot open cache dir: ' . $this->path);
            return false;
        }
        while (false !== ($file = readdir($handle))) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $full = $this->path . $file;
            if (is_file($full) && time() - filemtime($full) > $this->ttl) {
                @unlink($full);
            }
        }
        closedir($handle);
        return true;
    }

    /**
     * Generate a code that isn't currently in use for this app.
     * Alphabet excludes vowels and easily-confused characters so codes are
     * safe to read aloud and type on a device with no keyboard.
     */
    public function generateCode($app, $length = 5) {
        $alphabet = 'BCDFGHJKLMNPQRSTVWXZ23456789';
        $max = strlen($alphabet) - 1;
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
        } while (is_file($this->fileFor($app, $code)));
        return $code;
    }
}
