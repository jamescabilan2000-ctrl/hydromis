<?php

function hydromis_gmail_config(): array {
    $config = ['username'=>getenv('GMAIL_SMTP_USER')?:'', 'app_password'=>getenv('GMAIL_SMTP_APP_PASSWORD')?:'', 'from_name'=>'HydroMIS'];
    $path = __DIR__ . '/gmail.local.php';
    if (is_file($path)) { $local=require $path; if(is_array($local))$config=array_replace($config,$local); }
    return $config;
}
function normalize_gmail(string $email): string { return strtolower(trim($email)); }
function valid_gmail(string $email): bool { return (bool)preg_match('/\A[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@gmail\.com\z/i', normalize_gmail($email)); }
function ensure_customer_email_schema($conn): void {
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_lookup CHAR(64) NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL");
    $conn->query("CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_email_lookup ON users(email_lookup)");
}
function smtp_read($socket): string { $out=''; do{$line=fgets($socket,515);if($line===false)break;$out.=$line;}while(isset($line[3])&&$line[3]==='-');return $out; }
function smtp_expect($socket,array $codes): string { $reply=smtp_read($socket);$code=(int)substr($reply,0,3);if(!in_array($code,$codes,true))throw new RuntimeException('Mail server rejected the request.');return $reply; }
function smtp_command($socket,string $command,array $codes): void { fwrite($socket,$command."\r\n");smtp_expect($socket,$codes); }
function send_gmail_message(string $to,string $subject,string $html): bool {
    $cfg=hydromis_gmail_config();$user=trim((string)$cfg['username']);$pass=preg_replace('/\s+/','',(string)$cfg['app_password']);
    if($user===''||$pass===''){error_log('[HydroMIS] Gmail SMTP credentials are not configured.');return false;}
    try{
        $socket=stream_socket_client('ssl://smtp.gmail.com:465',$errno,$errstr,15,STREAM_CLIENT_CONNECT);
        if(!$socket)throw new RuntimeException($errstr);stream_set_timeout($socket,15);smtp_expect($socket,[220]);
        smtp_command($socket,'EHLO hydromis.local',[250]);smtp_command($socket,'AUTH LOGIN',[334]);smtp_command($socket,base64_encode($user),[334]);smtp_command($socket,base64_encode($pass),[235]);
        smtp_command($socket,'MAIL FROM:<'.$user.'>',[250]);smtp_command($socket,'RCPT TO:<'.$to.'>',[250,251]);smtp_command($socket,'DATA',[354]);
        $fromName=str_replace(["\r","\n"],'',(string)$cfg['from_name']);$subject=str_replace(["\r","\n"],'',$subject);
        $headers="From: {$fromName} <{$user}>\r\nTo: <{$to}>\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        fwrite($socket,$headers."\r\n\r\n".str_replace("\n.","\n..",$html)."\r\n.\r\n");smtp_expect($socket,[250]);smtp_command($socket,'QUIT',[221]);fclose($socket);return true;
    }catch(Throwable $e){error_log('[HydroMIS] Gmail send failed: '.$e->getMessage());return false;}
}
function send_customer_otp(string $email,string $code,string $purpose): bool {
    $title=$purpose==='registration'?'Verify your HydroMIS registration':'Your HydroMIS login code';
    $safe=htmlspecialchars($code,ENT_QUOTES,'UTF-8');
    return send_gmail_message($email,$title,'<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:30px"><h2 style="color:#083b5c">HydroMIS verification</h2><p>Use this one-time code to '.$purpose.':</p><div style="font-size:34px;font-weight:800;letter-spacing:8px;color:#087fa9;margin:24px 0">'.$safe.'</div><p>This code expires in 10 minutes. If you did not request it, you can ignore this email.</p></div>');
}

