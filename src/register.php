<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function handleRegister(array $postData, array $config, array &$eventInfo): int {
    /* re-read data with exclusive lock for persistence */
    $fp = fopen($config["shiftFile"], "r+");
    if( ! flock($fp, LOCK_EX) ) {
        die("ERROR: Cannot obtain file lock.");
    }
    $rawData = fread($fp, filesize($config["shiftFile"]));
    $eventInfo = json_decode($rawData, true);

    $entryMail = htmlspecialchars(trim($postData["data-mail"] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    /* store user data */
    $entry = array(
        "entryName" => htmlspecialchars($postData["data-name"], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        "entryMail" => $entryMail,
        "entryTimestamp" => time(),
        "entryHash" => hash("sha256", $config["hashSalt"] . time() . $postData["data-name"] . $postData["data-mail"])
    );
    $taskName = $postData["data-task"];
    $shiftName = $postData["data-shift"];

    /* find correct task and shift */
    $tasks = $eventInfo["eventTasks"];
    $taskIndex = array_search($taskName, array_map(fn($t) => html_entity_decode($t['taskName']), $tasks), true);
    $shifts = $tasks[$taskIndex]['taskShifts'];
    $shiftIndex = array_search($shiftName, array_map(fn($s) => html_entity_decode($s['shiftName']), $shifts), true);
    /* prevent invalid shifts */
    if($taskIndex === FALSE || $shiftIndex === FALSE) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return MSG_REGISTER_UNKNOWN;
    }

    /* error prevention */
    if( ! isset($eventInfo["eventTasks"][$taskIndex]["taskShifts"][$shiftIndex]["entries"])) {
        $eventInfo["eventTasks"][$taskIndex]["taskShifts"][$shiftIndex]["entries"] = [];
    }

    /* check if there is space left in this shift */
    if(count($eventInfo["eventTasks"][$taskIndex]["taskShifts"][$shiftIndex]["entries"]) 
        < $eventInfo["eventTasks"][$taskIndex]["taskShifts"][$shiftIndex]["shiftSlots"]) {
        /* generate feedback mail */
        $mail = new PHPMailer(true);
        try {
            /* smtp connection settings */
            $mail->isSMTP();
            $mail->Host = $config["mail"]["smtpserv"];
            $mail->SMTPAuth = true; 
            $mail->Username = $config["mail"]["username"];
            $mail->Password = $config["mail"]["password"];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            /* smtp mail settings */
            $mail->setFrom($config["mail"]["fromaddress"], $config["mail"]["fromname"]);
            $mail->addAddress($entry["entryMail"], $entry["entryName"]);
            $mail->addReplyTo($config["mail"]["replyToAddress"], $config["mail"]["replyToName"]);
            $mail->CharSet = "UTF-8";
            /* content */
            $mail->isHTML(false);
            $mail->Subject = "Bestätigung Helfer " . $eventInfo["eventName"];
            $mail->Body = "Hallo {$entry["entryName"]}!\n\nVielen Dank für Deine Hilfe. Du hast Dich für folgende Schicht eingetragen:\n
Veranstaltung: {$eventInfo["eventName"]}
Datum: {$eventInfo["eventDate"]}
Schicht: {$taskName} ({$shiftName})\n
Falls Du Dich abmelden möchtest, benutze bitte folgenden Link: \n
{$config["baseUrl"]}?action=unregisterDialog&hash={$entry["entryHash"]}\n
(Falls kein Abmelde-Dialog angezeigt wird, schalte deinen Ad-Blocker aus oder wechsle den Browser.)\n
Du erhältst ein paar Tage vor der Veranstaltung eine weitere Mail mit genaueren Informationen zu Deiner Schicht.
\n
Viele Grüße
{$eventInfo["eventOrganizer"]}";
            $mail->send();
        } catch (Exception $e) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return MSG_REGISTER_FAILURE;
        }
        /* write user data back to json */
        $eventInfo["eventTasks"][$taskIndex]["taskShifts"][$shiftIndex]["entries"][] = $entry;
        /* write back to json file (with exclusive lock since read above) */
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($eventInfo, JSON_PRETTY_PRINT));
        fflush($fp);
        /* output message for user */
        $msg = MSG_REGISTER_SUCCESS;
    } else {
        /* no space left in this shift */
        $msg = MSG_REGISTER_NOSPACE;
    } 
    flock($fp, LOCK_UN);
    fclose($fp);
    return $msg;
}
