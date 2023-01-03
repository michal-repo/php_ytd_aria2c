<?php

require "aria2.php";
include_once "config.php";

$default_name_value = "";

function redirectToSelf($opt = "")
{
    header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]) . $opt);
    die();
}


if (isset($_POST['cleanUpCookies']) && $_POST['cleanUpCookies'] === "yes") {
    $files_bucket[] = glob('tmp/*');
    $files_bucket[] = glob('cookies/*');
    foreach ($files_bucket as $files) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    php2Aria2c::removeDispatchedURLsFromInternalQueue();
    redirectToSelf(("?status=" . urlencode("Cleaned up!")));
}

if (isset($_POST['processOneFromInternalQueue']) && $_POST['processOneFromInternalQueue'] === "yes") {
    $continue = true;
    if (isset($_POST['uselockfile']) && $_POST['uselockfile'] === "yes") {
        $lockfile = 'lock';
        $fp = fopen($lockfile, "r");
        if (flock($fp, LOCK_EX | LOCK_NB) === false) {
            $status = "Couldn't get the lock!";
            $continue = false;
            fclose($fp);
        }
    }
    if ($continue) {
        $status = php2Aria2c::processOneElementFromInternalQueue();
    }
    if (isset($_POST['uselockfile']) && $_POST['uselockfile'] === "yes" && $continue) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    redirectToSelf(("?status=" . urlencode($status)));
}

if (isset($_POST['showQueuedDownloads']) && $_POST['showQueuedDownloads'] === "yes") {
    list($show_results, $results_to_print) = php2Aria2c::listInternalQueue('active', true);
}

if (isset($_POST['showQueueHistory']) && $_POST['showQueueHistory'] === "yes") {
    list($show_results, $results_to_print) = php2Aria2c::listInternalQueue('history', true);
}

$actions = ['addToAria2cByID' => 'addToAria2cByID', 'removeDownloadByID' => 'removeDownloadByID'];
foreach ($actions as $action => $method) {
    if (isset($_POST[$action])) {
        $status = php2Aria2c::$method(intval($_POST[$action]));
        redirectToSelf(("?status=" . urlencode($status)));
    }
}

if (isset($_POST['processNowByID'])) {
    $status = php2Aria2c::processOneElementFromInternalQueue(intval($_POST['processNowByID']), true);
    redirectToSelf(("?status=" . urlencode($status)));
}

if (isset($_POST['redownloadByID'])) {
    $status = php2Aria2c::setDispatchedByID(intval($_POST['redownloadByID']), 0);
    redirectToSelf(("?status=" . urlencode($status)));
}

if (isset($_POST['changeDispatchStatusByID'])) {
    $status = php2Aria2c::setDispatchedByID(intval($_POST['changeDispatchStatusByID']), 1);
    redirectToSelf(("?status=" . urlencode($status)));
}

if (isset($_POST['editDownloadByID'])) {
    $data = php2Aria2c::getDownloadByID(intval($_POST['editDownloadByID']));
    foreach ($data as $key => $field) {
        if ($key === "opt") {
            $downloadOptions = unserialize($field);
            $_POST['out_name'] = $downloadOptions['out'];
            $_POST['dir_name'] = $downloadOptions['dir'];
        } else {
            $_POST[$key] = $field;
        }
    }
    $_POST['addToInternalQueue'] = "true";
    $_POST['skipCookies'] = "true";
    $edit_form = true;
}

if (isset($_POST['url'])) {
    $url = $_POST['url'];
    if (!empty($url)) {
        $php2Aria2c = new php2Aria2c($url);
        if (isset($_POST['selected_extractor']) && !empty($_POST['selected_extractor'])) {
            $php2Aria2c->setCredentialsID($_POST['selected_extractor']);
        }
        $available_formats = $php2Aria2c->fetchFormats(((isset($_POST['skipCachedFormats']) && $_POST['skipCachedFormats'] == "true") ? true : false))->getFormats();
        $available_credentials = $php2Aria2c->getListOfCredentials();
    }
}

if (isset($php2Aria2c) && isset($_POST['formatOption']) && in_array($_POST['formatOption'], $available_formats) && !$edit_form) {
    $php2Aria2c->setSelectedFormat($_POST['formatOption']);
    if (isset($_POST['out_name']) && $_POST['out_name'] !== $default_name_value) {
        $php2Aria2c->setOutName($_POST['out_name']);
    }
    if (isset($_POST['dir_name']) && !empty($_POST['dir_name'])) {
        $php2Aria2c->setDirName($_POST['dir_name']);
    }
    if (isset($_POST['skipCookies']) && $_POST['skipCookies'] == "true") {
        $php2Aria2c->setCookiesUsage(0);
    }
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        $php2Aria2c->setID($_POST['id']);
    }
    $php2Aria2c->save();
    if ($_POST['addToInternalQueue'] == "true") {
        $status = $php2Aria2c->addToInternalQueue();
    } else {
        $status = $php2Aria2c->addToAria2cQueue();
    }
    redirectToSelf(("?status=" . urlencode($status)));
} elseif (isset($_POST['formatOption']) && !$edit_form) {
    echo "Selected invalid format";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
    <title>ytd aria2c UI</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon" />
    <link rel="stylesheet" href="bootstrap\css\bootstrap.min.css">
    <script src="bootstrap\js\bootstrap.min.js"></script>
</head>

<body>
    <div class="container">
        <?php if (isset($_GET['status'])) { ?>
            <div class="row">
                <div class="col">
                    <div class="alert alert-success" role="alert">
                        <?php echo urldecode($_GET['status']) ?>
                    </div>
                </div>
            </div>
        <?php } ?>
        <div class="row">
            <div class="col">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method='POST'>
                    <div class="mb-3">
                        <?php
                        if (isset($_POST['id'])) {
                        ?>
                            <input type="hidden" value="<?php echo $_POST['id']; ?>" id="id" name="id">
                        <?php
                        }
                        ?>
                        <label for="url" class="form-label">URL</label>
                        <input type="text" class="form-control" id="url" name="url" aria-describedby="urlHelp" <?php
                                                                                                                if (isset($_POST['url'])) {
                                                                                                                    echo 'value="' . $_POST['url'] . '"';
                                                                                                                }
                                                                                                                ?>>
                        <div id="urlHelp" class="form-text">URL to download.</div>
                        <label for="out_name" class="form-label">Save as</label>
                        <input type="text" class="form-control" id="out_name" name="out_name" aria-describedby="out_nameHelp" <?php
                                                                                                                                if (isset($_POST['out_name'])) {
                                                                                                                                    echo 'value="' . $_POST['out_name'] . '"';
                                                                                                                                } else {
                                                                                                                                    echo 'value="' . $default_name_value . '"';
                                                                                                                                }
                                                                                                                                ?>>
                        <div id="out_nameHelp" class="form-text">Specify custom name (optional), will save to default name returned by URL if not specified.</div>
                        <label for="dir_name" class="form-label">Save location</label>
                        <input type="text" class="form-control" id="dir_name" name="dir_name" aria-describedby="dir_nameHelp" <?php
                                                                                                                                if (isset($_POST['dir_name'])) {
                                                                                                                                    echo 'value="' . $_POST['dir_name'] . '"';
                                                                                                                                }
                                                                                                                                ?>>
                        <div id="dir_nameHelp" class="form-text">Specify custom save location (optional), will save to default from aria2c config if not specified.</div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="true" id="addToInternalQueue" name="addToInternalQueue" aria-describedby="addToInternalQueueHelp" <?php if ((isset($_POST['addToInternalQueue']) && $_POST['addToInternalQueue'] === "true") || (!$_POST['url'] && !isset($_POST['addToInternalQueue']) && $GLOBALS['config']['add_to_internal_queue_by_default'])) {
                                                                                                                                                                                            echo "checked";
                                                                                                                                                                                        } ?>><label class="form-check-label" for="addToInternalQueue">
                                Add to internal queue
                            </label>
                        </div>
                        <div id="addToInternalQueueHelp" class="form-text">If checked, URL will be added to internal queue, URL will be prepared and added to aria2c if there will be empty download slot.</br>Useful if you download fails because of expired URL from source page.</div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="true" id="skipCookies" name="skipCookies" aria-describedby="skipCookiesHelp" <?php if ((isset($_POST['skipCookies']) && $_POST['skipCookies'] === "true") || (!$_POST['url'] && !isset($_POST['skipCookies'])  && !$GLOBALS['config']['use_cookies_by_default'])) {
                                                                                                                                                                    echo "checked";
                                                                                                                                                                } ?>> <label class="form-check-label" for="skipCookies">
                                Don't use Cookie Jar
                            </label>
                        </div>
                        <div id="skipCookiesHelp" class="form-text">Skip usage of cookies file when adding to aria2c.</div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="true" id="skipCachedFormats" name="skipCachedFormats" aria-describedby="skipCachedFormatsHelp"> <label class="form-check-label" for="skipCachedFormats">
                                Skip formats cache.
                            </label>
                        </div>
                        <div id="skipCachedFormatsHelp" class="form-text">Skip usage of cached formats, will fetch available formats via youtube-dl.</div>

                        <?php
                        if (isset($available_credentials) && is_array($available_credentials)) {
                        ?>
                            <div class="form-check">
                                <select id="selected_extractor" name="selected_extractor" class="form-select">
                                    <option value="0" <?php if (!isset($_POST["selected_extractor"])) {
                                                            echo "selected";
                                                        } ?>>Select extractor credentials</option>
                                    <?php
                                    foreach ($available_credentials as $cred) {
                                    ?>
                                        <option value="<?php echo $cred["id"]; ?>" <?php if (isset($_POST["selected_extractor"]) && $_POST["selected_extractor"] === $cred["id"]) {
                                                                                        echo "selected";
                                                                                    } ?>><?php echo $cred["extractor"]; ?></option>
                                    <?php
                                    }
                                    ?>
                                </select>
                            </div>
                        <?php
                        }


                        if (isset($available_formats) && is_array($available_formats)) {
                        ?>
                            <hr>
                            <label for="formatOption" class="form-label">Format options, select one <small>(if list is empty, url is unsupported)</small></label>
                            <?php
                            foreach ($available_formats as $name => $format) {
                                if (empty($format) && $format !== "0") {
                                    continue;
                                }
                            ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="formatOption" id="<?php echo $format; ?>" value="<?php echo $format; ?>" <?php if (isset($_POST['formatOption']) && $_POST['formatOption'] === $format) {
                                                                                                                                                                    echo "checked";
                                                                                                                                                                } ?>>
                                    <label class="form-check-label" for="<?php echo $format; ?>">
                                        <?php echo $name; ?>
                                    </label>
                                </div>
                        <?php
                            }
                        }
                        ?>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php if (isset($available_formats) && $edit_form) {
                                                                        echo "Update";
                                                                    } elseif (isset($available_formats) && !empty($available_formats) ) {
                                                                        echo "Add to queue";
                                                                    } else {
                                                                        echo "Get formats";
                                                                    } ?></button>
                </form>
            </div>
        </div>
        <hr>
        <div class="row">
            <div class="col">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method='POST' class="float-end">
                    <input type="hidden" value="yes" id="cleanUpCookies" name="cleanUpCookies">
                    <button type="submit" class="btn btn-danger">Clean up cookies & dispatched jobs</button>
                </form>
            </div>
        </div>
        </br>
        <div class="row">
            <div class="col">
                <h6 class="float-end"><?php echo php2Aria2c::getCountFromInternalQueue() ?></h6>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method='POST' class="float-end">
                    <input type="hidden" value="yes" id="processOneFromInternalQueue" name="processOneFromInternalQueue">
                    <input type="hidden" value="yes" id="uselockfile" name="uselockfile">
                    <button type="submit" class="btn btn-success">Process one URL from Queue</button>
                </form>
            </div>
        </div>
        <hr>
        <div class="row">
            <div class="col">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method='POST' class="float-end">
                    <input type="hidden" value="yes" id="showQueuedDownloads" name="showQueuedDownloads">
                    <button type="submit" class="btn btn-info">Show queued downloads</button>
                </form>
            </div>
        </div>
        </br>
        <div class="row">
            <div class="col">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method='POST' class="float-end">
                    <input type="hidden" value="yes" id="showQueueHistory" name="showQueueHistory">
                    <button type="submit" class="btn btn-warning">Show queue history</button>
                </form>
            </div>
        </div>
    </div>
    <?php
    if (isset($show_results)) {
    ?>
        <div class="container-fluid" id="results">
            <div class="row">
                <div class="col-12">
                    <?php
                    echo $results_to_print;
                    ?>
                </div>
            </div>
        </div>
    <?php
    }
    ?>
</body>


</html>