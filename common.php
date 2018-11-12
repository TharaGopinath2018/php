<?php

//Set PHP execution time
//-----------------------------
ini_set('max_execution_time', 0);
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);

//Set Timezone - To get Anniversary dates
//-----------------------------
date_default_timezone_set('Asia/Calcutta');
define("ROOT_PATH_MAIL", "c:\wamp\www\iDeal\\");
define("ROOT_IMAGE_PATH", "c:\wamp\www\iDeal_V1\bCard\images\\");
//define("ROOT_IMAGE_PATH", "c:\wamp\www\iDealcron\bCard\images\\");

//Init
//-----------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'ideal_beta');
define('DB_USER_NAME', 'ideal2');
define('DB_USER_PASS', 'passw0rd@123');


//Config
define('TODAY_DATE', mktime(0,0,0, date('n'), date('j'), date('Y')));

$config['CompanyStructure'] = array(
	'PE_ID' => '2',
	'EIT_ID' => '5',
	'HR_ID' => '13',
	'OP_ID' => '15',
	'FI_ID' => '19',
	'CO_ID' => '21',
	'SA_ID' => '23',
	
);

// MySQL DB Connection
//-----------------------------
$mysqlConnection = mysql_connect(DB_HOST, DB_USER_NAME, DB_USER_PASS) or die('Unable to connect to MySQL server');
$mysqlDB = mysql_select_db(DB_NAME) or die('Unable to connect to Database');

function getCronFunctionId($name)
{
	$sql		=	"SELECT id FROM function_names WHERE type = 'Cron' AND name='".$name."'";	
	$result		=	mysql_query($sql);
	$row		=	mysql_fetch_row($result);	
	return $row[0];
}

/* Start mail configuration  */
function mail_conf_common($function_id, $mailtotype, $structure_id = null) {	
	$cond_mailtype	=	null;
	$cond_struc_id	=	null;
	
	if(is_array($mailtotype))
		$cond_mailtype	=	' AND mail_to_type IN("'.implode('","', $mailtotype).'")';
	else
		$cond_mailtype	=	' AND mail_to_type = "'.$mailtotype.'"';		
	
	if(!empty($structure_id))
		$cond_struc_id	=	' AND structure_id = '.$structure_id;
	
	$sql = 'SELECT  Employee.work_email_address, MailConfiguration.employee_id, mail_to_type , Employee.first_name
			FROM mail_configurations AS MailConfiguration 
			INNER JOIN employees AS Employee 
				ON (MailConfiguration.employee_id = Employee.employee_number)			
			WHERE function_id='.$function_id.$cond_struc_id.$cond_mailtype;
	
	//echo $sql;
	
	$result		=	mysql_query($sql);
	$email_arr	=	array();

	while($row	= mysql_fetch_assoc($result))
	{		
		if($row['mail_to_type'] === 'to')
			$email_arr['first_name']		=	$row['first_name'];
		
		$email_arr[$row['mail_to_type']][]	=	$row['work_email_address'];	
	}
	
	return $email_arr;
}

function emailConfiguration() {
	
	$sql	=	'SELECT ConfigurationValues.configuration_key, ConfigurationValues.configuration_value 
		FROM configuration_values AS ConfigurationValues 
		INNER JOIN configuration_values AS a 
			ON (ConfigurationValues.parent_id = a.id) 
		WHERE a.configuration_key = "mail_details" ';
	
	$result		=	mysql_query($sql);
	$email_config_values	=	array();
	
	while ($row = mysql_fetch_array($result)) {
		$email_config_values[$row[0]]	=	$row[1];
	}
	
	return $email_config_values;
}
function arEmailConfiguration() {
	
	$sql	=	'SELECT ConfigurationValues.configuration_key, ConfigurationValues.configuration_value 
		FROM configuration_values AS ConfigurationValues 
		INNER JOIN configuration_values AS a 
			ON (ConfigurationValues.parent_id = a.id) 
		WHERE a.configuration_key = "ar_mail_config" ';
	
	$result		=	mysql_query($sql);
	$email_config_values	=	array();
	
	while ($row = mysql_fetch_array($result)) {
		$email_config_values[$row[0]]	=	$row[1];
	}
	
	return $email_config_values;
}

function get_schedular_configuration($name) {	
	$sql	= 'SELECT * FROM schedules WHERE name ="'.$name.'" AND status = 1';                	
	$result	= mysql_query($sql);
	$schedular_config_values	=	array();	
	while ($row = mysql_fetch_assoc($result)) {             
                $schedular_config_values[]	 =	$row;           
	}	
	return $schedular_config_values;
}

function log_insert($schedule_id,$actual_datetime){
    $sql = "INSERT INTO scheduler_logs (schedule_id, date_runned,date_cron)
    VALUES ($schedule_id, UNIX_TIMESTAMP(NOW()),$actual_datetime)";    
    $result	=  mysql_query($sql);     
    return mysql_insert_id();
}

function log_update($last_inserted_id,$message,$status){
    $sql = "UPDATE scheduler_logs SET log = '$message', status = $status WHERE  id = $last_inserted_id";    
    $result	=  mysql_query($sql);     
}

function get_log_events($scheduler_name, $sequence = null, $seq_value =null, $hr_sequence =null, $hr_seq_value=null, $check_today, $scheduler_id) {
        
		if(empty($scheduler_id))
			$scheduler_id = get_scheduler_id($scheduler_name);
        
        $sql	= 'SELECT id, schedule_id, log, status, date_runned,date_cron FROM scheduler_logs WHERE schedule_id ="'.$scheduler_id.'"';
        
		if(!empty($check_today))
		{			
			$sql .= ' AND date_cron >= '.TODAY_DATE;
		}

        if(!empty($sequence))
        {
            $sql .= ' AND FROM_UNIXTIME(date_cron, "%'.$sequence.'") = '.$seq_value;
        }
        if(!empty($hr_sequence))
        {
            $sql .= ' AND FROM_UNIXTIME(date_cron, "%'.$hr_sequence.'") = '.$hr_seq_value;
        }
	
        $sql .= ' ORDER BY date_runned DESC LIMIT 1';
        //echo $sql;
	$result	= mysql_query($sql);
	$log_values	=	array();	
	while ($row = mysql_fetch_assoc($result)) {             
                $log_values	 =	$row;           
	}        
	return $log_values;
}

function get_scheduler_id($scheduler_name){    
    $sql	= 'SELECT id FROM schedules WHERE name ="'.$scheduler_name.'"  AND status = 1 LIMIT 1';                	
    $result	=  mysql_query($sql);    
    $id = mysql_fetch_assoc($result);    
    return $id['id'];    
}

/**
 * Cron EMail From Names 
 */
$email_config_values = emailConfiguration();
define('HR_MAIL_FROM_NAME', $email_config_values['HR_MAIL_FROM_NAME']);
define('IDEAL_ADMIN_FROM_NAME', $email_config_values['IDEAL_ADMIN_FROM_NAME']);
define('IDEAL_APPLICATION_FROM_NAME', $email_config_values['IDEAL_APPLICATION_FROM_NAME']);
/**
 * Notification Mail send to Admin when cron run fails
 * 
 */
function sendErrorLogMail($cron_filename, $error_msg, $date_ran)
{
	global $email_config_values;
	
	$mail = new PHPMailer(true); // the true param means it will throw exceptions on errors, which we need to catch
	$mail->IsSMTP(); // telling the class to use SMTP                  
	$mail->SMTPDebug  = 2;                     // enables SMTP debug information (for testing)
	$mail->SMTPAuth   = true;                  // enable SMTP authentication
	$mail->SMTPSecure = "tls";                 // sets the prefix to the servier            
	$mail->Host = $email_config_values['host'];  // specify main and backup server
	$mail->Port = $email_config_values['port'];
	$mail->Username = $email_config_values['dhr_username'];  // SMTP username
	$mail->Password = $email_config_values['dhr_password']; // SMTP password   
	$mail->FromName   = HR_MAIL_FROM_NAME;
	$mail->From     = $email_config_values['dhr_username'];
	$mail->Sender   = $email_config_values['dhr_username'];
	$mail->AddReplyTo($email_config_values['dhr_username'],HR_MAIL_FROM_NAME);
	//$mail->AddAddress('anitha.jansyrani@hindujatech.com');
	$mail->SetFrom($email_config_values['dhr_username'],HR_MAIL_FROM_NAME);
	$mail->Subject = 'Cron failed '.$cron_filename.' at '.$date_ran; 

	//$mail->AddBCC('arunkumar.sampath@hindujatech.com', 'Testing');	

	$mail->AltBody = 'To view the message, please use an HTML compatible email viewer!'; // optional - MsgHTML will create an alternate automatically

	$contentToAdd = dirname(__FILE__).'\contents.html';

	$messageToAdd = 'Dear Team, <br> ';
	
$messageToAdd .= <<<CONTENT
<body style="margin: 10px;">
<div style="width: 640px; font-family: Arial, Helvetica, sans-serif; font-size: 14px;">
<center>
<br>$cron_filename Cron is failed to run at $date_ran. <br>
<div align="center"><img src="C:\wampn\www\iDeal_V1\bCard\images\\$imagePath" style="height: 418px; width: 499px"></div>
<br>Error:<br>
		$error_msg
</center>
Regards,<br>Ideal Admin,
<div align="left">Hinduja Tech</div>
</div>
</body>
CONTENT;
		  	
	$mail->MsgHTML($messageToAdd); print_r($mail);           
	$mail->Send();
         
}

