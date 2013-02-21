<?php

class Usercycle
{
    private static $version = '1';
    private static $protocol = 'http';
    private static $http_header_messages = array(
        200 => '200: Success (upon a successful GET, PUT, or DELETE request)',
        201 => '201: Created (upon a successful POST request)',
        400 => '400: Resource Invalid (improperly-formatted request)',
        401 => '401: Unauthorized (incorrect or missing authentication credentials)',
        404 => '404: Resource Not Found (requesting a non-existent person or other resource)',
        405 => '405: Method Not Allowed (e.g., trying to POST to a URL that responds only to GET)',
        406 => '406: Not Acceptable (server can\'t satisfy the Accept header specified by the client)',
        422 => '422: Unprocessable Entity (The request was well-formed but was unable to be followed due to semantic errors)',
        500 => '500: Server Error',
    );
    private $api_key;
    private $host = 'api.usercycle.com';
    private $port = 80;
    private $base_api_url;
    private $log_dir = '/tmp';
    private $logs = array();
    private $use_cron = false;

    public function __construct($access_token, $options=array())
    {
        $this->api_key = $access_token;
        $this->log_dir = isset($options['log_dir']) && $options['log_dir'] ? $options['log_dir'] : $this->log_dir;
        $this->use_cron = isset($options['use_cron']) ? $options['use_cron'] : $this->use_cron;
        $this->host = isset($options['host']) && $options['host'] ? $options['host'] : $this->host;
        $this->port = isset($options['port']) && $options['port'] ? $options['port'] : $this->port;
        $this->base_api_url = $this->base_url($this->host);
        if (! is_writable($this->log_dir))
        {
            throw new InvalidArgumentException('Could not open ' . $this->log_dir . ' for writing');
        }
    }

    private function base_url($host)
    {
        return static::$protocol . '://' . $host . '/api/v' . static::$version;
    }

    public function create($identity, $action, $properties = array(), $occurred_at = null)
    {
        $occurred_at = $occurred_at ?: time();
        $occurred_at = strftime('%Y-%m-%d %H:%M:%S UTC', $occurred_at);
        try
        {
            $params = array (
                'uid' => $identity,
                'action_name' => $action,
                'properties' => $properties,
                'occurred_at' => $occurred_at,
            );
            $this->process_event('POST', '/events.json', $params);
        }
        catch(Exception $e)
        {
            $this->log_error($e->getMessage());
        }
    }

    protected function process_event($method, $url, $params)
    {
        if($this->use_cron)
        {
            $this->log_event($method, $url, $params);
        }
        else
        {
            try
            {
                $this->send_event($method, $url, $params);
            }
            catch(Exception $e)
            {
                $this->log_event($method, $url, $params);
                $this->log_error($e->getMessage());
            }
        }
    }

    protected function log_event($method, $url, $data) {
        $data = array(
            'method' => $method,
            'url' => $url,
            'data' => $data,
        );
        $this->log('event', json_encode($data));
    }

    protected function send_event($method, $url, $data)
    {
        $curl = null;
        try
        {
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->base_api_url . $url,
                CURLOPT_PORT => $this->port,
                CURLOPT_HTTPHEADER => array(
                    'X-Usercycle-API-Key: ' . $this->api_key,
                    'Accept: application/json',
                ),
                CURLOPT_FRESH_CONNECT => true, //Don't cache requests
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 40,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => strtoupper($method) == 'POST',
                CURLOPT_POSTFIELDS => http_build_query($data),
            ));

            $result = curl_exec($curl);
            $error = curl_error($curl);
            $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
        }
        catch(Exception $e)
        {
            if($curl)
            {
                curl_close($curl);
            }
            throw new Exception($e->getMessage() . "for host " . $this->host);
        }

        if($error || $http_status < 200 || $http_status >= 300)
        {
            $msg = isset(static::$http_header_messages[$http_status]) ? static::$http_header_messages[$http_status] : $error;
            $this->log_error($msg);
        }
        return json_decode($result);
    }

    public function send_logged_events()
    {
        set_error_handler(array($this,'error_handler'));
        try
        {
            if (!is_file($this->log_name('event')))
            {
                restore_error_handler();
                return;
            }

            $event_log = $this->log_name('event');
            $send_log = $this->log_name('send');

            rename($event_log, $send_log);
            $events_file = fopen($send_log, "r");
            if ($events_file) {
                while (!feof($events_file))
                {
                    if ($single_event = fgets($events_file))
                    {
                        $single_event = json_decode($single_event, true);
                        $this->send_event($single_event['method'], $single_event['url'], $single_event['data']);
                    }
                }
                fclose($events_file);
            }
            unlink($send_log);
            restore_error_handler();
        }
        catch(Exception $e)
        {
            restore_error_handler();
            $this->log_error($e->getMessage());
        }
    }

    function error_handler($errno, $errstr, $errfile, $errline)
    {
        static $handler_depth = 0;
        if(++$handler_depth <= 4) {
            $this->log_error("[$errno] $errstr on line $errline in file $errfile");
        }
        $handler_depth--;
    }

    protected function log_name($type)
    {
        if ($log_name = isset($this->logs[$type]) ? $this->logs[$type] : null)
        {
            return $log_name;
        }

        $filename = "usercycle_{$type}.log";
        if($type == 'send') {
            $filename  = time() . '.' . $filename;
        }
        $this->logs[$type] = $this->log_dir . '/' . $filename;
        return $this->logs[$type];
    }

    protected function log_query($msg) { $this->log('event',$msg); }

    protected function log_send($msg) { $this->log('send',$msg); }

    protected function log_error($msg, $to_stderr = true)
    {
        $error_msg = '[' . time() . '] ' . $msg;
        $this->log('error', $error_msg);
        if($to_stderr) {
            error_log($error_msg);
        }
    }

    protected function log($type, $msg)
    {
        set_error_handler(array($this,'error_handler'));
        try
        {
            $fh = fopen($this->log_name($type),'a');
            if($fh) {
                fputs($fh, $msg . "\n");
                fclose($fh);
            }
            restore_error_handler();
        }
        catch(Exception $e)
        {
            restore_error_handler();
        }
    }
}

if ( basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : null) == basename(__FILE__) )
{
    if (! isset($argv[1]))
    {
        error_log('At least one argument required. Usage: php usercycle.php <access_token> [<log_dir>] [<host>]');
        exit(1);
    }

    $usercycle = new Usercycle($argv[1], array(
        'log_dir' => isset($argv[2]) ? $argv[2] : null,
        'host' => isset($argv[3]) ? $argv[3] : null,
    ));
    $usercycle->send_logged_events();
}