<?php

include_once "config.php";

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
            'id' => ((string) random_int(1, 999999)),
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
    private $md5URLID;
    private $cookiesPath;
    private $availableFormatsPath;
    private $out;
    private $dir;
    private $useCookiesForAria2c;
    private $opt;
    private $connection;
    private $aria2;
    private $credID;
    private $ID;

    function __construct($url = "", $selectedFormat = null, $formats = null, $outName = null, $dirName = null, $useCookiesForAria2c = 1, $credID = null)
    {
        $this->url = $url;
        $this->selectedFormat = $selectedFormat;
        $this->formats = $formats;
        $this->md5URLID = md5($this->url);
        $this->cookiesPath = '"cookies/' . $this->md5URLID . '.cookiesjar"';
        $this->availableFormatsPath = 'tmp/' . $this->md5URLID . '.formats';
        $this->out = $outName;
        $this->dir = $dirName;
        $this->credID = $credID;
        $this->useCookiesForAria2c = $useCookiesForAria2c;
        $this->opt = array();
        $this->initDB();
    }

    public function fetchFormats($force_pull = false)
    {
        $this->formats = array();
        if (file_exists($this->availableFormatsPath) && !$force_pull) {
            $this->formats = unserialize(file_get_contents($this->availableFormatsPath));
        } else {
            $creds = $this->getCreds();
            $out = shell_exec('youtube-dl "' . $this->url . '" --dump-json' . $creds);
            $json_formats = json_decode($out, 1);
            if (isset($json_formats)) {
                foreach ($json_formats['formats'] as $format) {
                    $this->formats[$format['format']] = $format['format_id'];
                }
            }
            file_put_contents($this->availableFormatsPath, serialize($this->formats));
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

    public function setCookiesUsage($val)
    {
        $this->useCookiesForAria2c = $val;
    }

    public function setCredentialsID($id)
    {
        $this->credID = intval($id);
    }

    public function setID($id)
    {
        $this->ID = $id;
    }

    private function getCreds()
    {
        $cred_str = "";
        if (!is_null($this->connection) && (isset($this->credID) && is_int($this->credID) && $this->credID > 0)) {
            $stmt = $this->connection->prepare("select login, password from credentials where id = :id");
            $stmt->bindParam(':id', $this->credID);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $cred_str = " --username " . $data['login'] . " --password " . $data['password'];
        }
        return $cred_str;
    }

    private function generateOptionsForAria2c()
    {
        if (!is_null($this->out)) {
            $this->opt['out'] = $this->out;
        }
        if (!is_null($this->dir)) {
            $this->opt['dir'] = $this->dir;
        }
        if (!is_null($this->cookiesPath)) {
            $this->opt['load-cookies'] = $this->cookiesPath;
        }
    }

    public function addToInternalQueue()
    {
        if (!is_null($this->connection)) {
            $this->generateOptionsForAria2c();
            $stmt = $this->connection->prepare("update downloads set dispatched = 0 where id = :id");
            $stmt->bindParam(':id', $this->ID);
            $stmt->execute();
            $status = "Added to internal queue";
        } else {
            $status = "Unable to connect to local database.";
        }
        return $status;
    }

    public static function processOneElementFromInternalQueue(int $id = null, bool $force = false)
    {
        $self = new self();
        return $self->pProcessOneElementFromInternalQueue($id, $force);
    }

    public function pProcessOneElementFromInternalQueue(int $id = null, bool $force = false)
    {
        if (!$this->checkForFreeSlots() && !$force) {
            return "No free slots...";
        }
        if (!is_null($this->connection)) {
            if (is_null($id)) {
                $stmt = $this->connection->prepare("select * from downloads where dispatched = 0 order by id asc limit 1");
            } else {
                $stmt = $this->connection->prepare("select * from downloads where id = :id");
                $stmt->bindParam(':id', $id);
            }
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($data === false) {
                return "Queue is empty";
            }
            $this->fillObjData($data);
            list($status, $code) = $this->checkAria2cDispatchStatus($this->dispatchToAria2c());
            if ($code === 1) {
                $this->setDispachedInDBByID($data['id']);
            }
        } else {
            $status = "Unable to connect to local database.";
        }
        return $status;
    }

    private function fillObjData(array $data)
    {
        $this->url = $data['url'];
        $this->selectedFormat = $data['formatOption'];
        $this->cookiesPath = $data['cookiesPath'];
        $this->useCookiesForAria2c = $data['useCookiesForAria2c'];
        $this->opt = unserialize($data['opt']);
        $this->ID = $data['id'];
    }

    private function setDispachedInDBByID($id)
    {
        if (!is_null($this->connection)) {
            $stmt = $this->connection->prepare("update downloads set dispatched = 1 where id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
        }
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
        list($status, $unused) = $this->checkAria2cDispatchStatus($this->dispatchToAria2c());
        return $status;
    }

    public function save()
    {
        $this->generateOptionsForAria2c();
        if (!is_null($this->connection)) {
            if (isset($this->ID)) {
                $stmt = $this->connection->prepare("update downloads set url = :url, formatOption = :formatOption, cookiesPath = :cookiesPath, useCookiesForAria2c = :useCookiesForAria2c, opt = :opt where id = :id");
                $stmt->bindParam(':id', $this->ID);
            } else {
                $stmt = $this->connection->prepare("insert into downloads (url, formatOption, cookiesPath, useCookiesForAria2c, opt, dispatched, addedTime) values (:url, :formatOption, :cookiesPath, :useCookiesForAria2c, :opt, 1, datetime('now', 'localtime'))");
            }
            $stmt->bindParam(':url', $this->url);
            $stmt->bindParam(':formatOption', $this->selectedFormat);
            $stmt->bindParam(':cookiesPath', $this->cookiesPath);
            $stmt->bindParam(':useCookiesForAria2c', $this->useCookiesForAria2c);
            $stmt->bindParam(':opt', serialize($this->opt));
            $stmt->execute();
            if (!isset($this->ID)) {
                $this->ID = $this->connection->lastInsertId();
            }
        }
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
        $creds = $this->getCreds();
        $url = shell_exec('youtube-dl -f "' . $this->selectedFormat . '" "' . $this->url . '" -g ' . (($this->useCookiesForAria2c == 0) ? '' : '--cookies ' . $this->cookiesPath) . $creds);
        $this->setDownloadURL($this->ID, trim($url));
        sleep($GLOBALS['config']['delay_after_ytd_url_generation']);
        return $this->addURLToAria2c(trim($url));
    }

    private function addURLToAria2c($url)
    {
        $this->connect2aria2c();
        $options = $this->opt;
        if ($this->useCookiesForAria2c == 0) {
            unset($options['load-cookies']);
        }
        return $this->aria2->addUri(
            [$url],
            $options
        );
    }

    private function setDownloadURL($id, $url)
    {
        if (!is_null($this->connection)) {
            $stmt = $this->connection->prepare("update downloads set download_url = :value where id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':value', $url);
            $stmt->execute();
        }
    }

    private function initDB()
    {
        try {
            $initDB = false;
            if (!is_file((isset($GLOBALS['config']['db_location']) ? $GLOBALS['config']['db_location'] : __DIR__) . "/php_aria2.db")) {
                $initDB = true;
            }
            $this->connection = new PDO("sqlite:" . (isset($GLOBALS['config']['db_location']) ? $GLOBALS['config']['db_location'] : __DIR__) . "/php_aria2.db");
            if ($initDB) {
                $stmt = $this->connection->prepare('create table downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, download_url TEXT, formatOption TEXT NOT NULL, useCookiesForAria2c INTEGER DEFAULT 1 NOT NULL, cookiesPath TEXT NOT NULL, opt TEXT NOT NULL, dispatched INTEGER DEFAULT 0 NOT NULL, addedTime TEXT NOT NULL) ');
                $stmt->execute();
                $stmt = $this->connection->prepare('create table credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, extractor TEXT NOT NULL, login TEXT NOT NULL, password TEXT NOT NULL, main_page_url TEXT NOT NULL) ');
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
        $numWaiting = $globStat['result']['numWaiting'];
        if ($numWaiting > 0) {
            $globPaused = $this->aria2->tellWaiting(0, 9999999);
            foreach ($globPaused['result'] as $entry) {
                if ($entry['status'] === "paused") {
                    $numWaiting--;
                }
            }
        }
        if (($globStat['result']['numActive'] + $numWaiting) < $globOptions['result']['max-concurrent-downloads']) {
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
        $link45deg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-link-45deg" viewBox="0 0 16 16"><path d="M4.715 6.542 3.343 7.914a3 3 0 1 0 4.243 4.243l1.828-1.829A3 3 0 0 0 8.586 5.5L8 6.086a1.002 1.002 0 0 0-.154.199 2 2 0 0 1 .861 3.337L6.88 11.45a2 2 0 1 1-2.83-2.83l.793-.792a4.018 4.018 0 0 1-.128-1.287z"/><path d="M6.586 4.672A3 3 0 0 0 7.414 9.5l.775-.776a2 2 0 0 1-.896-3.346L9.12 3.55a2 2 0 1 1 2.83 2.83l-.793.792c.112.42.155.855.128 1.287l1.372-1.372a3 3 0 1 0-4.243-4.243L6.586 4.672z"/></svg>';
        $pencilfill = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-pencil-fill" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>';
        $trash = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>';
        $toggleon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-toggle-on" viewBox="0 0 16 16"><path d="M5 3a5 5 0 0 0 0 10h6a5 5 0 0 0 0-10H5zm6 9a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>';
        $bidownload = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>';
        $toggleoff = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-toggle-off" viewBox="0 0 16 16"><path d="M11 4a4 4 0 0 1 0 8H8a4.992 4.992 0 0 0 2-4 4.992 4.992 0 0 0-2-4h3zm-6 8a4 4 0 1 1 0-8 4 4 0 0 1 0 8zM0 8a5 5 0 0 0 5 5h6a5 5 0 0 0 0-10H5a5 5 0 0 0-5 5z"/></svg>';
        $arrowdown = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-arrow-down-square-fill" viewBox="0 0 16 16"><path d="M2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2zm6.5 4.5v5.793l2.146-2.147a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 1 1 .708-.708L7.5 10.293V4.5a.5.5 0 0 1 1 0z"/></svg>';

        $self = new self();
        $results = $self->pListInternalQueue($type);
        if ($results === null) {
            return [true, "Unable to connect to local database."];
        }
        if (empty($results)) {
            return [true, "Queue for selected status is empty."];
        }
        if ($beautify) {
            $table = '<table class="table"><thead><tr>';
            $ths = array_keys($results[0]);
            foreach ($ths as $th) {
                $table .= '<th scope="col">' . ($th === "opt" ? "Out Filename" : $th) . '</th>';
            }
            $table .= '<th scope="col">Actions</th>';
            $table .= '</tr></thead><tbody>';

            foreach ($results as $row) {
                $table .= '<tr>';
                foreach ($row as $key => $col) {
                    $table .= '<td>' . ((($key === "url" || $key === "download_url") && ($col !== "" && !is_null($col)))
                        ? "<a href=" . $col . " target=\"_blank\">" . $link45deg . "</a>"
                        : ($key === "opt" ? unserialize($col)['dir'] . unserialize($col)['out'] : $col)) . '</td>';
                }
                $table .= '<td>';
                $table .= '<form action="' . htmlspecialchars($_SERVER["PHP_SELF"]) . '" method="POST">
                    <input type="hidden" value="' . $row['id'] . '" id="editDownloadByID" name="editDownloadByID">
                    <button type="submit" class="btn btn-success" title="Edit this download job.">' . $pencilfill . '</button>
                </form>';
                if ($type == 'active') {
                    $table .= '<form action="' . htmlspecialchars($_SERVER["PHP_SELF"]) . '" method="POST">
                    <input type="hidden" value="' . $row['id'] . '" id="removeDownloadByID" name="removeDownloadByID">
                    <button type="submit" class="btn btn-danger" title="Remove this download job.">' . $trash . '</button>
                </form>
                <form action="' . htmlspecialchars($_SERVER["PHP_SELF"]) . '" method="POST">
                <input type="hidden" value="' . $row['id'] . '" id="changeDispatchStatusByID" name="changeDispatchStatusByID">
                <button type="submit" class="btn btn-info" title="Switch dispach status to done.">' . $toggleon . '</button>
            </form>
            <form action="' . htmlspecialchars($_SERVER["PHP_SELF"]) . '" method="POST">
                <input type="hidden" value="' . $row['id'] . '" id="processNowByID" name="processNowByID">
                <button type="submit" class="btn btn-warning" title="Process this job now.">' . $bidownload . '</button>
            </form>';
                } else {
                    $table .= '<form action="' . htmlspecialchars($_SERVER["PHP_SELF"]) . '" method="POST">
                    <input type="hidden" value="' . $row['id'] . '" id="redownloadByID" name="redownloadByID">
                    <button type="submit" class="btn btn-dark" title="Reset dispach status to scheduled.">' . $toggleoff . '</button>
                </form>
                <form action="' . htmlspecialchars($_SERVER["PHP_SELF"]) . '" method="POST">
                <input type="hidden" value="' . $row['id'] . '" id="addToAria2cByID" name="addToAria2cByID">
                <button type="submit" class="btn btn-primary" title="Add this url to aria2c now.">' . $arrowdown . '</button>
            </form>';
                }
                $table .= '</td></tr>';
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
            $stmt = $this->connection->prepare("select id, url, formatOption, download_url, opt, addedTime from downloads where dispatched = " . $type_code);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            return null;
        }
        return $data;
    }

    public static function setDispatchedByID(int $id, int $value)
    {
        $self = new self();
        return $self->pSetDispatchedByID($id, $value);
    }

    public function pSetDispatchedByID(int $id, int $value)
    {
        if (!is_null($this->connection)) {
            $stmt = $this->connection->prepare("update downloads set dispatched = :value where id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':value', $value);
            $r = $stmt->execute();
            if ($r) {
                $status = "Queued download ID: " . $id . " updated dispatched value to " . $value . ".";
            } else {
                $status = "Unable to update queued download ID: " . $id . ".";
            }
        } else {
            $status = "Unable to connect to local database.";
        }
        return $status;
    }

    public static function addToAria2cByID(int $id)
    {
        $self = new self();
        return $self->pAddToAria2cByID($id);
    }

    public function pAddToAria2cByID(int $id)
    {
        if (!is_null($this->connection)) {
            $data = $this->pGetDownloadByID($id);
            $this->fillObjData($data);
            list($status, $code) = $this->checkAria2cDispatchStatus($this->addURLToAria2c($data['download_url']));
            if ($code === 1) {
                $this->setDispachedInDBByID($id);
            }
        } else {
            $status = "Unable to connect to local database.";
        }
        return $status;
    }

    private function pGetDownloadByID(int $id)
    {
        $stmt = $this->connection->prepare("select * from downloads where id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function removeDownloadByID(int $id)
    {
        $self = new self();
        return $self->pRemoveDownloadByID($id);
    }

    public function pRemoveDownloadByID(int $id)
    {
        if (!is_null($this->connection)) {
            $stmt = $this->connection->prepare("delete from downloads where id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $status = "Removed download job.";
        } else {
            $status = "Unable to connect to local database.";
        }
        return $status;
    }

    public function getListOfCredentials()
    {
        if (!is_null($this->connection)) {
            $stmt = $this->connection->prepare("select id, extractor from credentials");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $data;
        } else {
            return "Unable to connect to local database.";
        }
    }

    public static function getDownloadByID(int $id)
    {
        $self = new self();
        return $self->pGetDownloadByID($id);
    }

    public function findByURL()
    {
        if (!is_null($this->connection)) {
            $stmt = $this->connection->prepare("select * from downloads where url = :url order by id");
            $stmt->bindParam(':url', $this->url);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $data;
        } else {
            return "Unable to connect to local database.";
        }
    }
}
