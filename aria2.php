<?php

// https://github.com/shiny/php-aria2

class Aria2
{
    protected $ch;
    protected $token;
    protected $batch = false;
    protected $batch_cmds = [];

    function __construct($server = 'http://127.0.0.1:6800/jsonrpc', $token = null)
    {
        $this->ch = curl_init($server);
        $this->token = $token;
        curl_setopt_array($this->ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false
        ]);
    }

    function __destruct()
    {
        curl_close($this->ch);
    }

    protected function req($data)
    {
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);
        return curl_exec($this->ch);
    }

    function batch($func = null)
    {
        $this->batch = true;
        if (is_callable($func)) {
            $func($this);
        }
        return $this;
    }

    function inBatch()
    {
        return $this->batch;
    }

    function commit()
    {
        $this->batch = false;
        $cmds = json_encode($this->batch_cmds);
        $result = $this->req($cmds);
        $this->batch_cmds = [];
        return $result;
    }

    function __call($name, $arg)
    {
        if (!is_null($this->token)) {
            array_unshift($arg, $this->token);
        }

        //Support system methods
        if (strpos($name, '_') === false) {
            $name = 'aria2.' . $name;
        } else {
            $name = str_replace('_', '.', $name);
        }

        $data = [
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => $name,
            'params' => $arg
        ];
        //Support batch requests
        if ($this->batch) {
            $this->batch_cmds[] = $data;
            return $this;
        }
        $data = json_encode($data);
        $response = $this->req($data);
        if ($response === false) {
            trigger_error(curl_error($this->ch));
        }
        return json_decode($response, 1);
    }
}

class php2Aria2c
{
    private $url;
    private $selectedFormat;
    private $formats;
    private $cookiesId;
    private $cookiesPath;
    private $out;
    private $dir;
    private $opt;
    private $connection;
    private $aria2;

    function __construct($url = "", $selectedFormat = null, $formats = null, $outName = null, $dirName = null)
    {
        $this->url = $url;
        $this->selectedFormat = $selectedFormat;
        $this->formats = $formats;
        $this->cookiesId = md5($this->url);
        $this->cookiesPath = '"cookies/' . $this->cookiesId . '.cookiesjar"';
        $this->out = $outName;
        $this->dir = $dirName;
        $this->opt = array();
        $this->initDB();
    }

    public function fetchFormats()
    {
        $this->formats = array();
        $out = shell_exec('youtube-dl "' . $this->url . '" -F');
        $lines = explode(PHP_EOL, $out);
        $start = false;
        foreach ($lines as $line) {
            if (strpos($line, "format") !== false && strpos($line, "resolution") !== false && strpos($line, "extension") !== false && !$start) {
                $start = true;
                continue;
            } elseif (!$start) {
                continue;
            }

            $format_code = substr($line, 0, strpos($line, " "));
            $this->formats[$line] = $format_code;
        }
        return $this;
    }

    public function getFormats()
    {
        return $this->formats;
    }


    public function setSelectedFormat($format)
    {
        $this->selectedFormat = $format;
    }

    public function setOutName($outName)
    {
        $this->out = $outName;
    }

    public function setDirName($dirName)
    {
        $this->dir = $dirName;
    }

    private function generateOptionsForAria2c()
    {
        $this->opt['out'] = $this->out;
        $this->opt['dir'] = $this->dir;
        $this->opt['load-cookies'] = $this->cookiesPath;
    }

    public function addToInternalQueue()
    {
        if (!is_null($this->connection)) {
            $this->generateOptionsForAria2c();
            $stmt = $this->connection->prepare("insert into downloads (url, formatOption, cookiesPath, opt, addedTime) values (:url, :formatOption, :cookiesPath, :opt, datetime('now', 'localtime'))");
            $stmt->bindParam(':url', $this->url);
            $stmt->bindParam(':formatOption', $this->selectedFormat);
            $stmt->bindParam(':cookiesPath', $this->cookiesPath);
            $stmt->bindParam(':opt', serialize($this->opt));
            $stmt->execute();
            $status = "Added to internal queue";
        } else {
            $status = "Unable to connect to local database.";
        }
        return $status;
    }

    public static function processOneElementFromInternalQueue()
    {
        $self = new self();
        return $self->pProcessOneElementFromInternalQueue();
    }

    public function pProcessOneElementFromInternalQueue()
    {
        if (!$this->checkForFreeSlots()) {
            return "No free slots...";
        }
        if (!is_null($this->connection)) {
            $stmt = $this->connection->prepare("select * from downloads where dispatched = 0 order by id asc limit 1");
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($data === false) {
                return "Queue is empty";
            }
            $this->url = $data['url'];
            $this->selectedFormat = $data['formatOption'];
            $this->cookiesPath = $data['cookiesPath'];
            $this->opt = unserialize($data['opt']);
            list($status, $code) = $this->checkAria2cDispatchStatus($this->dispatchToAria2c());
            if ($code === 1) {
                $stmt = $this->connection->prepare("update downloads set dispatched = 1 where id = :id");
                $stmt->bindParam(':id', $data['id']);
                $stmt->execute();
            }
        } else {
            $status = "Unable to connect to local database.";
        }
        return $status;
    }

    public static function removeDispatchedURLsFromInternalQueue()
    {
        $self = new self();
        return $self->pRemoveDispatchedURLsFromInternalQueue();
    }

    public function pRemoveDispatchedURLsFromInternalQueue()
    {
        $stmt = $this->connection->prepare("delete from downloads where dispatched = 1");
        $stmt->execute();
        return true;
    }

    public function addToAria2cQueue()
    {
        $this->generateOptionsForAria2c();
        list($status, $unused) = $this->checkAria2cDispatchStatus($this->dispatchToAria2c());
        return $status;
    }

    private function checkAria2cDispatchStatus($out)
    {
        if (isset($out['id'])) {
            $status = "Added to aria2c queue";
            $code = 1;
        } else {
            $status = "Unable to add URL to aria2c queue";
            $code = 0;
        }
        return array($status, $code);
    }

    private function dispatchToAria2c()
    {
        $url = shell_exec('youtube-dl -f "' . $this->selectedFormat . '" "' . $this->url . '" -g --cookies ' . $this->cookiesPath);
        $this->connect2aria2c();
        $out = $this->aria2->addUri(
            [$url],
            $this->opt
        );
        return $out;
    }

    private function initDB()
    {
        try {
            $initDB = false;
            if (!is_file(__DIR__ . "/php_aria2.db")) {
                $initDB = true;
            }
            $this->connection = new PDO("sqlite:" . __DIR__ . "/php_aria2.db");
            if ($initDB) {
                $stmt = $this->connection->prepare('create table downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, formatOption TEXT NOT NULL, cookiesPath TEXT NOT NULL, opt TEXT NOT NULL, dispatched INTEGER DEFAULT 0 NOT NULL, addedTime TEXT NOT NULL) ');
                $stmt->execute();
            }
        } catch (Exception $e) {
            $this->connection = NULL;
            echo "Unable to connect to local database.<br>" . $e->getMessage();
        }
    }

    public static function getCountFromInternalQueue()
    {
        $self = new self();
        return $self->pGetCountFromInternalQueue();
    }

    public function pGetCountFromInternalQueue()
    {
        if (!is_null($this->connection)) {
            $stmt = $this->connection->prepare("select count(*) as count from downloads where dispatched = 0");
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $status = $data['count'] . " items in internal queue.";
        } else {
            $status = "Unable to connect to local database.";
        }
        return $status;
    }


    private function checkForFreeSlots()
    {
        $this->connect2aria2c();
        $globOptions = $this->aria2->getGlobalOption();
        $globStat = $this->aria2->getGlobalStat();
        if (($globStat['result']['numActive'] + $globStat['result']['numWaiting']) < $globOptions['result']['max-concurrent-downloads']) {
            return true;
        }
        return false;
    }

    private function connect2aria2c()
    {
        if (!isset($this->aria2)) {
            $this->aria2 = new Aria2();
        }
    }

    public static function listInternalQueue($type = 'active', $beautify = false)
    {
        $self = new self();
        $results = $self->pListInternalQueue($type);
        if ($results === null) {
            return [true, "Unable to connect to local database."];
        }
        if ($beautify) {
            $table = '<table class="table"><thead><tr>';
            $ths = array_keys($results[0]);
            foreach ($ths as $th) {
                $table .= '<th scope="col">' . $th . '</th>';
            }
            $table .= '</tr></thead><tbody>';

            foreach ($results as $row) {
                $table .= '<tr>';
                foreach ($row as $col) {
                    $table .= '<td>' . $col . '</td>';
                }
                $table .= '</tr>';
            }

            $table .= "</tbody></table>";
        }
        return [true, $table];
    }

    public function pListInternalQueue($type)
    {
        if (!is_null($this->connection)) {
            switch ($type) {
                case 'history':
                    $type_code = 1;
                    break;
                default:
                case 'active':
                    $type_code = 0;
                    break;
            }
            $stmt = $this->connection->prepare("select * from downloads where dispatched = " . $type_code);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            return null;
        }
        return $data;
    }
}
