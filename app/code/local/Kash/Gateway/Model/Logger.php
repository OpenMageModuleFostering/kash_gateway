<?php

class Kash_Gateway_Model_Logger
{
    protected $logFile = null;
    protected $shopName = '';

    public function __construct($params = array())
    {
        if ($params) {
            $this->shopName = array_shift($params);
        }

        $logDir = Mage::getBaseDir("log");
        if (!is_dir($logDir)) {
            mkdir($logDir);
            chmod($logDir, 0750);
        }
        $this->logFile = $logDir . DIRECTORY_SEPARATOR . 'kash.log';
    }

    //log a message to our kash.log
    public function log($msg)
    {
        file_put_contents($this->logFile, $this->shopName." ".date('c')." ".print_r($msg, true)."\n", FILE_APPEND | LOCK_EX);
    }

    public function getLog()
    {
        $result = @file_get_contents($this->logFile);
        return $result===FALSE ? date('c')." Could not read kash log" : $result;
    }

    /**
     * Erase the log file once it's been sent to our server. In case it's been
     * written to while we're sending it back, erase only the first $length
     * characters and leave the rest for next time.
     */
    public function resetLog($length)
    {
        $file = @fopen($this->logFile, "r+");
        if (!$file) {
            return;
        }

        if (flock($file, LOCK_EX)) {
            $contents = '';
            while (!feof($file)) {
                $contents .= fread($file, 8192);
            }
            ftruncate($file, 0);
            rewind($file);
            fwrite($file, substr($contents, $length));
            fflush($file);
            flock($file, LOCK_UN);
        }
        fclose($file);
    }
}
