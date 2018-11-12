<?php

$cron_filename = 'debtors_bdm_notification';
require_once('common.php');
require_once('classFiles/class.phpmailer.php');
include 'ExcelClasses/PHPExcel/IOFactory.php';
$function_id = getCronFunctionId($cron_filename);
$log_flag = FALSE;
$schedular_config = get_schedular_configuration($cron_filename);
$cur_time = mktime(date('H'), date('i'), 0, 0, 0, 0);
foreach ($schedular_config as $value) {
    $schedular_time = mktime($value['hour'], $value['minute'], 0, 0, 0, 0);

        if ($cur_time <= $schedular_time && date('w') == $value['dayofweek'])          
        {
        $schedule_id = $value['id'];
        $actual_datetime = mktime($value['hour'], $value['minute'], date('s'), date('m'), date('d'), date('y'));
    }
}
try {
	 if (empty($function_id))
        throw new Exception('Configuration missing for filename ' . $cron_filename);

    if(PHP_SAPI !=='cli')
        throw new Exception('Run Script Through Scheduler');

    if (empty($schedule_id))
        throw new Exception('Today is not cron scheduling day');    
	
    $log_value = get_log_events($cron_filename, null, null, null, null, null, $schedule_id);

    if ($log_value['status'] == 0 || empty($log_value)) {
        $log_flag = FALSE;
    }

    if ($log_flag == FALSE) {
        $last_inserted_id = log_insert($schedule_id, $actual_datetime);

        $file_path = getcwd() . "/debtors_report";
        if (!file_exists($file_path)) {
            mkdir($file_path, 0777, true);
        }

        $file_cwd = str_replace("\\", "/", $file_path);
        getBdmList($file_cwd);

        $log_message = "";
        $log_status = 1;
    }
} catch (Exception $e) {
    $log_status = 0;
    $log_message = "Failure " . strip_tags($e->getMessage());
    echo $e->getMessage();
}
if (isset($last_inserted_id) && $last_inserted_id) {
    log_update($last_inserted_id, $log_message, $log_status);
    if ($log_status === 0)
        sendErrorLogMail($cron_filename, $log_message, Date('d/m/Y'));
}

function formatMoney($rupee, $cur = 'USD') {
    $detect_negative = 0;
    if ($rupee < 0) {
        $rupee = abs($rupee);
        $detect_negative = 1;
    }
    $last_three = '';
    $data = '';
    $r = (string) $rupee;
    $r = round($r);
    $r = (string) $r;
    $lastThree = '';
    $otherNumbers = '';
    $arr = '';
    if (strlen($r) > 3) {
        $lastThree = substr($r, -3);
        $otherNumbers = substr($r, 0, -3);
    } else {
        $last_three = $r;
        $otherNumbers = '';
    }
    if ($otherNumbers != '') {
        $last_three = (string) $lastThree;
        $otherNumbers = (string) $otherNumbers;
        $split = ($cur == 'INR') ? 2 : 3;
        $arr = str_split(strrev($otherNumbers), $split);
        for ($i = count($arr) - 1; $i >= 0; $i--) {
            $data .= strrev($arr[$i]) . ',';
        }
    }

    $return_data = $data . $last_three;

    if ($detect_negative == 1) {
        $return_data = '(' . $return_data . ')';
    }

    if ($return_data == 0 && $detect_negative != 1) {
        $return_data = '';
    }
    return $return_data;
}
function _ccList($bdm_id, $sbu, $emp_number) {

    $cclist_query2 = "select CONCAT(emp.first_name,' ',emp.last_name) AS employee_name,emp.work_email_address from general_approvers as ga	
                    left join function_names as fn on(fn.id = ga.function_id)
                    left join employees as emp on(emp.employee_number = ga.employee_id) 
                    where fn.name LIKE CONVERT( _utf8 'debtors_ccmail_empbase_" . $emp_number . "' USING latin1 ) 
                    COLLATE latin1_swedish_ci and ga.status = 1";
    $cc_mail = array();
    $cc = array();
    $bdm = array();

    $ccdata_array = @mysql_query($cclist_query2);
    while ($ccdata = mysql_fetch_array($ccdata_array)) {
       // $name = explode(".", $ccdata['work_email_address']);
      //  $cc[$name[0]] = $ccdata['work_email_address'];
        $cc[$ccdata['employee_name']] = $ccdata['work_email_address'];
    }
    $cc_mail['cc'] = $cc;
    return $cc_mail;
}

function getBdmList($file_cwd) {

    $bdm = "SELECT
		pt.configuration_value as bdm_id
		FROM configuration_values AS pt
		WHERE pt.configuration_key = 'bdm_id'";
    $bdmArray = mysql_fetch_array(@mysql_query($bdm));

    $deb_bdm = "select distinct(bdm_id), CONCAT_WS(' ',emp.first_name, emp.last_name) AS bdm_name,"
            . " emp.work_email_address AS work_email_address,"
            . " emp.employee_number AS empNumber, emp.structure_name as SBU "
            . " from debtor_reports dr "
            . "LEFT JOIN employees emp ON dr.bdm_id=emp.id";
    $deb_bdm_list = @mysql_query($deb_bdm);

    $bdmData = array();
    while ($data = mysql_fetch_array($deb_bdm_list)) {
//        if(($data['bdm_id'] == 383) ||($data['bdm_id'] == 779)) continue; //2293,383,259,2461,94,1880
      
        $bdmData[] = $data['bdm_id'];
        $bdmData[] = $data['bdm_name'];
        $bdmData[] = $data['work_email_address'];
        $cc_mail_list = _ccList($data['bdm_id'], $data['SBU'], $data['empNumber']);
        $date_today = date("d-m-Y");
        $bdm_filename = str_replace(' ', '_', $data['bdm_name']);
        $date_format = date('d-M-Y');

        $file_name = 'debtors_report_' . $bdm_filename . '_' . $date_format;
        $file_name_type = $file_name . '.xls';
        $file_directory = $file_cwd . '/' . $file_name . '.xls';

        $file = '';
        $file['file_name_type'] = $file_name_type;
        $file['file_name'] = $file_name;
        $file['file_directory'] = $file_directory;

        debtors_report($bdmData, $date_today, $file, $cc_mail_list);
        $bdmData = '';
    }

    } 
function deleteFile($file_directory) {
    if (file_exists($file_directory)) {
        unlink($file_directory);
    }
}

function debtors_report($bdmData, $reportDate, $file, $cc_mail) {

    $invoices_query = "
(SELECT Invoice.id as `id`,
  `Customer`.`id` as `customer_id`, 
concat(Customer.customer_code,' - ',Customer.customer_name) as `cust_name`,
 `Customer`.`division` as `division`, 
 (case when `ProjectPurchaseOrder`.`attachment_reference_no` <> ''
        then `ProjectPurchaseOrder`.`attachment_reference_no`
        else (SELECT proj_po.attachment_reference_no
            FROM `po_so_masters` po_so
            LEFT JOIN project_purchase_orders proj_po ON po_so.po_id = proj_po.id
            AND proj_po.is_primary =1 WHERE po_so.sales_order_id=`SaleOrder`.id limit 1)
        end) as `po_no`, 
`CustomerContact`.`contact_person_name` as `contact_person_name`,
 `CustomerContact`.`contact_person_email`as `contact_person_email`,
 `CustomerContact`.`contact_person_mobile` as `contact_person_mobile`,
 `Invoice`.`invoice_code` as `invoice_number`,
 `Invoice`.`total_amount` as `amount_in_IC`,
 `Invoice`.`balance_amount` as `balance_in_IC`,
 (ForexConversion.value * Invoice.balance_amount) As balance_in_INR ,
 `Invoice`.`invoice_date` as `invoice_date`,
 `SaleOrder`.`credit_period` as `credit_period`,
 `Currency`.`currency_code`  as `invoicing_currency`,
 DATEDIFF(now(),DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) AS due_diff,
 DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day) AS `credit_due_date`,
 `SaleOrder`.`sales_order_code`, 
 `Projectalise`.`id` as `project_id`,
 `Projectalise`.`project_code` as `project_code`,
 `Projectalise`.`project_name` as `project_name`,
 concat(Employee.first_name,' ',Employee.last_name) as RSH,
 concat(SalesPerson.first_name,' ',SalesPerson.last_name) as BDM,
 concat(BUHname.first_name,' ',BUHname.last_name)as business_leader,
concat(PMname.first_name,' ',PMname.last_name)as PM,
 `Structure`.`name` as sbu,
 `LegalEntity`.`legal_entity` as `entity_name`,
 `LegalEntity`.`legal_entity_code`,
 `Invoice`.`invoice_date_submission_customer`,
 `Invoice`.`expected_collection_date`,
 DATEDIFF(now(),DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) AS days_past_due
 FROM `invoices` AS `Invoice` 
left JOIN projects AS `Projectalise` ON (`Invoice`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSo` ON (`Invoice`.`project_id` = `ProjectSo`.`project_id`) 
left JOIN project_purchase_orders AS `ProjectPurchaseOrder` ON (`ProjectPurchaseOrder`.`id` = `Invoice`.`po_id`) 
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSo`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN customer_contacts AS `CustomerContact` ON (`CustomerContact`.`id` = `SaleOrder`.`finance_contact_id`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `SaleOrder`.`customer_id`) 
left JOIN currencies AS `Currency` ON (`Currency`.`id` = `Projectalise`.`currency_id`) 
left JOIN employees AS `Employee` ON (`Employee`.`id` = `SaleOrder`.`rsh_id`) 
left JOIN employees AS `SalesPerson` ON (`SalesPerson`.`id` = `SaleOrder`.`sales_person_id`) 
left JOIN general_approvers AS `generalApprover` ON (`generalApprover`.`structure_id` = `SaleOrder`.`sbu` 
and `generalApprover`.`function_id` =4) 
left JOIN employees AS `BUHname` ON (`BUHname`.`employee_number` = `generalApprover`.`employee_id`) 
left JOIN employees AS `PMname` ON (`PMname`.`id` = `Projectalise`.`project_manager`) 
left JOIN company_structures AS `Structure` ON (`Structure`.`id` =  `SaleOrder`.`sbu`) 
left JOIN legal_entities AS `LegalEntity` ON (`LegalEntity`.`id` =  `SaleOrder`.`hinduja_entity_id`) 
left JOIN (select * from (select * from forex_conversions order by date desc) as new 
group by new.from_currency_id order by date desc) AS `ForexConversion` 
ON (`ForexConversion`.`from_currency_id` = `Projectalise`.`currency_id` and DATE_FORMAT(`ForexConversion`.`date`,'%Y-%m-%d') <= CURDATE())
left JOIN invoices AS `invoiceself` ON (`invoiceself`.`invoice_reference` =  `Invoice`.`id`)
WHERE ((`Invoice`.`status` = 'a') and (`Invoice`.`deleted` = '0')   AND  (`Invoice`.`balance_amount` > 0)  and`invoiceself`.`invoice_reference` is null
and(`Invoice`.`invoice_reference`is null or `Invoice`.`invoice_reference` = '' ) AND 
SalesPerson.id = " . $bdmData[0] . " ) 
 group by Invoice.id  ORDER BY `Invoice`.`invoice_date` ASC)
 UNION
 (
 SELECT concat('NON_',NonIdealInvoice.id) as `id`,
 `NonIdealInvoice`.`customer_id` as `customer_id`, 
 concat(NonIdealInvoice.customer_code,' - ',Customer.customer_name) as `cust_name`,
 ' ' as `division` ,
`NonIdealInvoice`.`po_no` as `po_no`,
`CustomerContact`.`contact_person_name` as `contact_person_name`,
`CustomerContact`.`contact_person_email`as `contact_person_email` ,
`CustomerContact`.`contact_person_mobile` as `contact_person_mobile`,
`NonIdealInvoice`.`invoice_code` as `invoice_number`, 
`NonIdealInvoice`.`total_amount`as `amount_in_IC`,
`NonIdealInvoice`.`balance_amount`as `balance_in_IC`,
(ForexConversion.value * NonIdealInvoice.balance_amount) As balance_in_INR ,
 `NonIdealInvoice`.`invoice_date` as `invoice_date`,
 `NonIdealInvoice`.`credit_period` as `credit_period`,
 `Currency`.`currency_code` as `invoicing_currency`,
 DATEDIFF(now(),DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) AS due_diff,
 DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day) AS `credit_due_date`,
 ' ' as `sales_order_code`,
' ' as `project_id`,
 ' ' as `project_code`,
 `NonIdealInvoice`.`project_name` as `project_name`,
 CONCAT(EmployeeRSH.first_name,' ',EmployeeRSH.last_name) as RSH,
 concat(SalesPerson.first_name,' ',SalesPerson.last_name) as BDM,
 concat(BUHname.first_name,' ',BUHname.last_name) as `business_leader`,
concat(PMname.first_name,' ',PMname.last_name)as `PM`,
`Structure`.`name` as sbu,
 `LegalEntity`.`legal_entity` as`entity_name`,
 `LegalEntity`.`legal_entity_code`,
 `NonIdealInvoice`.`invoice_date_submission_customer` as invoice_date_submission_customer,
 `NonIdealInvoice`.`expected_collection_date` as expected_collection_date,
 DATEDIFF(now(),DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) AS days_past_due
FROM `non_ideal_invoices` AS `NonIdealInvoice` left JOIN currencies AS `Currency` 
ON (`Currency`.`id` = `NonIdealInvoice`.`invoicing_currency_id`) left JOIN employees AS `EmployeeRSH` 
ON (`EmployeeRSH`.`id` = `NonIdealInvoice`.`rsh_id`) left JOIN employees AS `SalesPerson`
 ON (`SalesPerson`.`id` = `NonIdealInvoice`.`bdm_id`) left JOIN company_structures AS `Structure`
 ON (`Structure`.`id` = `NonIdealInvoice`.`sbu_id`) left JOIN customer_contacts AS `CustomerContact` 
ON (`CustomerContact`.`customer_id` = `NonIdealInvoice`.`customer_id`) LEFT JOIN `legal_entities` AS `LegalEntity` 
ON (`NonIdealInvoice`.`entity_id`=`LegalEntity`.`id`) left JOIN `employees` AS `Employee` ON (`Employee`.`id`=`NonIdealInvoice`.`bdm_id`)
left JOIN general_approvers AS `generalApprover` ON (`generalApprover`.`structure_id` = `NonIdealInvoice`.`sbu_id` 
and `generalApprover`.`function_id` =4)  
left JOIN employees AS `BUHname` ON (`BUHname`.`employee_number` = `generalApprover`.`employee_id`) 
left JOIN employees AS `PMname` ON (`PMname`.`id` = `NonIdealInvoice`.`pm_id`) 
left JOIN customers AS `Customer` ON (`Customer`.`customer_code` = `NonIdealInvoice`.`customer_code`) 
 left JOIN `company_structures` AS `CompanyStructure` ON (`CompanyStructure`.`id`=`NonIdealInvoice`.`id`) 
 left JOIN (select * from (select * from forex_conversions order by date desc) as new 
group by new.from_currency_id order by date desc) AS `ForexConversion` ON (`ForexConversion`.`from_currency_id` = `NonIdealInvoice`.`invoicing_currency_id` and DATE_FORMAT(`ForexConversion`.`date`,'%Y-%m-%d') <= CURDATE())
 left JOIN non_ideal_invoices AS `NonIdealInvoiceself` ON (`NonIdealInvoiceself`.`invoice_reference` =  `NonIdealInvoice`.`id`)
 WHERE (`NonIdealInvoice`.`balance_amount` > 0) and`NonIdealInvoiceself`.`invoice_reference` is null
AND SalesPerson.id = " . $bdmData[0] . " AND NonIdealInvoice.deleted=0 group by `NonIdealInvoice`.`invoice_code` 
 )
 UNION
 (
 SELECT concat('Cr_',CreditNote.id) as `id`,
 `CreditWithNoReference`.`customer_no` as `customer_id`, 
 concat(Customer.customer_code,' - ',Customer.customer_name) as `cust_name`,
 `Customer`.`division` as `division` ,
' ' as `po_no`,
`CustomerContact`.`contact_person_name` as `contact_person_name`,
`CustomerContact`.`contact_person_email`as `contact_person_email` ,
`CustomerContact`.`contact_person_mobile` as `contact_person_mobile`,
`CreditNote`.`mode_no` as `invoice_number`, 
CONCAT('(',`CreditNote`.`balance_amount`,')') as `amount_in_IC`,
CONCAT('(',`CreditNote`.`balance_amount`,')') as `balance_in_IC`,
CONCAT('(', ForexConversion.value * `CreditNote`.`balance_amount`,')') as balance_in_INR,
 `CreditNote`.`date` as `invoice_date`,
 '0' as `credit_period`,
 `CreditWithNoReference`.`currency` as `invoicing_currency`,
 '0' AS due_diff,
`CreditNote`.`date` AS credit_due_date,  
 `SaleOrder`.`sales_order_code`, 
`Projectalise`.`id` as `project_id`,
 `Projectalise`.`project_code` as `project_code`,
 `Projectalise`.`project_name` as `project_name`,
 CONCAT(EmployeeRSH.first_name,' ',EmployeeRSH.last_name) as RSH,
 concat(SalesPerson.first_name,' ',SalesPerson.last_name) as BDM,
 concat(BUHname.first_name,' ',BUHname.last_name) as `business_leader`,
 concat(PMname.first_name,' ',PMname.last_name) as `PM`,
`CreditWithNoReference`.`sbu` as sbu,
 `CreditWithNoReference`.`entity` as`entity_name`,
 `LegalEntity`.legal_entity_code,
 '0' as invoice_date_submission_customer,
 '0' as expected_collection_date,
 ' ' as days_past_due
FROM `credit_notes` AS `CreditNote` 
left JOIN credit_with_no_references AS `CreditWithNoReference` ON (`CreditWithNoReference`.`credite_note_id` = `CreditNote`.`id`) 
left JOIN projects AS `Projectalise` ON (`CreditWithNoReference`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSoMaster` ON (`CreditWithNoReference`.`project_id` = `ProjectSoMaster`.`project_id`)
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSoMaster`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN legal_entities AS `LegalEntity` ON (`LegalEntity`.`legal_entity` = `CreditWithNoReference`.`entity`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `CreditWithNoReference`.`customer_no`) 
left JOIN currencies AS `Currency` ON (`Currency`.`currency_code` = `CreditWithNoReference`.`currency`) 
left JOIN employees AS `EmployeeRSH` ON (`EmployeeRSH`.`id` = `CreditWithNoReference`.`rsh_id`)
 left JOIN employees AS `SalesPerson` ON (`SalesPerson`.`id` = `CreditWithNoReference`.`bdm_id`) 
 left JOIN general_approvers AS `generalApprover` ON (`generalApprover`.`structure_id` = `SaleOrder`.`sbu` 
and `generalApprover`.`function_id` =4) 
 left JOIN employees AS `BUHname` ON (`BUHname`.`employee_number` = `generalApprover`.`employee_id`) 
left JOIN employees AS `PMname` ON (`PMname`.`id` = `Projectalise`.`project_manager`) 
left JOIN customer_contacts AS `CustomerContact` ON (`CustomerContact`.`customer_id` = `CreditWithNoReference`.`customer_no`)
LEFT JOIN (select * from (select * from forex_conversions order by date desc) as new 
group by new.from_currency_id order by date desc) AS `ForexConversion` ON (`ForexConversion`.`from_currency_id` = `Currency`.`id` and DATE_FORMAT(`ForexConversion`.`date`,'%Y-%m-%d') <= CURDATE())
 WHERE (`CreditNote`.`balance_amount` > 0) AND `CreditWithNoReference`.bdm_id = " . $bdmData[0] . " and `CreditNote`.`deleted`=0 group by `CreditNote`.`mode_no`
)
union(
select Invoice.id as `id`,
  `Customer`.`id` as `customer_id`, 
concat(Customer.customer_code,' - ',Customer.customer_name) as `cust_name`,
 `Customer`.`division` as `division`, 
 (case when `ProjectPurchaseOrder`.`attachment_reference_no` <> ''
        then `ProjectPurchaseOrder`.`attachment_reference_no`
        else (SELECT proj_po.attachment_reference_no
            FROM `po_so_masters` po_so
            LEFT JOIN project_purchase_orders proj_po ON po_so.po_id = proj_po.id
            AND proj_po.is_primary =1 WHERE po_so.sales_order_id=`SaleOrder`.id limit 1)
        end) as `po_no`, 
`CustomerContact`.`contact_person_name` as `contact_person_name`,
 `CustomerContact`.`contact_person_email`as `contact_person_email`,
 `CustomerContact`.`contact_person_mobile` as `contact_person_mobile`,
 `Invoice`.`invoice_code` as `invoice_number`,
 `Invoice`.`total_amount` as `amount_in_IC`,
 `Invoice`.`balance_amount` as `balance_in_IC`,
 (ForexConversion.value * Invoice.balance_amount) As balance_in_INR ,
 `Invoice`.`invoice_date` as `invoice_date`,
 `SaleOrder`.`credit_period` as `credit_period`,
 `Currency`.`currency_code`  as `invoicing_currency`,
 DATEDIFF(now(),DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) AS due_diff,
 DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day) AS `credit_due_date`,
 `SaleOrder`.`sales_order_code`, 
 `Projectalise`.`id` as `project_id`,
 `Projectalise`.`project_code` as `project_code`,
 `Projectalise`.`project_name` as `project_name`,
 concat(Employee.first_name,' ',Employee.last_name) as RSH,
 concat(SalesPerson.first_name,' ',SalesPerson.last_name) as BDM,
 concat(BUHname.first_name,' ',BUHname.last_name)as business_leader,
concat(PMname.first_name,' ',PMname.last_name)as PM,
 `Structure`.`name` as sbu,
 `LegalEntity`.`legal_entity` as `entity_name`,
 `LegalEntity`.`legal_entity_code`,
 `Invoice`.`invoice_date_submission_customer`,
 `Invoice`.`expected_collection_date`,
 DATEDIFF(now(),DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) AS days_past_due
 FROM `invoices` AS `Invoice` 
 inner join invoice_consolidations as `InvoiceConsolidation` on (`Invoice`.`consolidated_id` = `InvoiceConsolidation`.`id`)
 left join invoice_consolidation_details as `InvoiceConsolidationDetail` on (`InvoiceConsolidation`.`id` = `InvoiceConsolidationDetail`.`invoice_consolidation_id`)
 left join invoices as `InvoiceSelf` on (`InvoiceSelf`.`id` = `InvoiceConsolidationDetail`.`invoice_id` )
left JOIN projects AS `Projectalise` ON (`InvoiceSelf`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSo` ON (`InvoiceSelf`.`project_id` = `ProjectSo`.`project_id`) 
left JOIN project_purchase_orders AS `ProjectPurchaseOrder` ON (`ProjectPurchaseOrder`.`id` = `InvoiceSelf`.`po_id`) 
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSo`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN customer_contacts AS `CustomerContact` ON (`CustomerContact`.`id` = `SaleOrder`.`finance_contact_id`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `SaleOrder`.`customer_id`) 
left JOIN currencies AS `Currency` ON (`Currency`.`id` = `Projectalise`.`currency_id`) 
left JOIN employees AS `Employee` ON (`Employee`.`id` = `SaleOrder`.`rsh_id`) 
left JOIN employees AS `SalesPerson` ON (`SalesPerson`.`id` = `SaleOrder`.`sales_person_id`) 
left JOIN general_approvers AS `generalApprover` ON (`generalApprover`.`structure_id` = `SaleOrder`.`sbu` 
and `generalApprover`.`function_id` =4) 
left JOIN employees AS `BUHname` ON (`BUHname`.`employee_number` = `generalApprover`.`employee_id`) 
left JOIN employees AS `PMname` ON (`PMname`.`id` = `Projectalise`.`project_manager`) 
left JOIN company_structures AS `Structure` ON (`Structure`.`id` =  `SaleOrder`.`sbu`) 
left JOIN legal_entities AS `LegalEntity` ON (`LegalEntity`.`id` =  `SaleOrder`.`hinduja_entity_id`) 
left JOIN (select * from (select * from forex_conversions order by date desc) as new 
group by new.from_currency_id order by date desc) AS `ForexConversion` 
ON (`ForexConversion`.`from_currency_id` = `Projectalise`.`currency_id` and DATE_FORMAT(`ForexConversion`.`date`,'%Y-%m-%d') <= CURDATE())
WHERE ((`Invoice`.`status` = 'a') and (`Invoice`.`deleted` = '0')   AND  (`Invoice`.`balance_amount` > 0)  and`invoiceself`.`invoice_reference` is null
and(`Invoice`.`invoice_reference`is null or `Invoice`.`invoice_reference` = '' ) AND 
SalesPerson.id = " . $bdmData[0] . " ) 
 group by Invoice.id  ORDER BY `Invoice`.`invoice_date` ASC)
";

    $debtors_array = mysql_query($invoices_query);

    $ind = 2;
    $excelData;
    $excelData[1][] = 'Customer Name';
    $excelData[1][] = 'Invoice Number';
    $excelData[1][] = 'Invoice Date';
    $excelData[1][] = 'Due Date';
    $excelData[1][] = 'Invoicing Currency';
    $excelData[1][] = 'Amount in IC';
    $excelData[1][] = 'Balance in IC';
    $excelData[1][] = 'Days of Due';
    $excelData[1][] = 'Due/Not Due';
    $excelData[1][] = 'Due Month';
    $excelData[1][] = 'Invoice Date Submission To Customer';
    $excelData[1][] = 'Expected Date of Collection';
    $excelData[1][] = 'Customer PO Ref.';
    $excelData[1][] = 'Credit Period';
    $excelData[1][] = 'Customer Contact Name';
    $excelData[1][] = 'Customer Email';
    $excelData[1][] = 'Customer Contact Number';
    $excelData[1][] = 'Project ID';
    $excelData[1][] = 'Project Name';

    if ($debtors_array) {
        while ($data_list = mysql_fetch_array($debtors_array)) {

            $excelData[$ind][] = $data_list['cust_name'];
            $excelData[$ind][] = $data_list['invoice_number'];
            $excelData[$ind][] = convert_date_format($data_list['invoice_date']);
            $excelData[$ind][] = convert_date_format($data_list['credit_due_date']);
            $excelData[$ind][] = $data_list['invoicing_currency'];
            $excelData[$ind][] = $data_list['amount_in_IC'];
            $excelData[$ind][] = $data_list['balance_in_IC'];
            $excelData[$ind][] = (($data_list['days_past_due'] < 0) || (trim($data_list['days_past_due']) == '')) ? 0 : $data_list['days_past_due'];

            $excelData[$ind][] = (!is_numeric($data_list['amount_in_IC'])) ? '' : //Credit Notes
                    (($data_list['days_past_due'] <= 0) ? 'No Due' : 'Due');

            $excelData[$ind][] = date("M-Y", strtotime($data_list['credit_due_date']));
            $excelData[$ind][] = ($data_list['invoice_date_submission_customer'] == 0 ) ? '-' : convert_date_format($data_list['invoice_date_submission_customer']);
            $excelData[$ind][] = ($data_list['expected_collection_date'] == 0) ? '-' : convert_date_format($data_list['expected_collection_date']);

            $excelData[$ind][] = $data_list['po_no'];
            $excelData[$ind][] = $data_list['credit_period'];
            $excelData[$ind][] = $data_list['contact_person_name'];
            $excelData[$ind][] = $data_list['contact_person_email'];
            $excelData[$ind][] = $data_list['contact_person_mobile'];
            $excelData[$ind][] = $data_list['project_code'];
            $excelData[$ind][] = $data_list['project_name'];

            $ind++;
        }
    }

    load_excel($excelData, $reportDate, $bdmData, $file, $cc_mail);
}
function load_excel($excelData, $reportDate, $bdmData, $file, $cc_mail) {
    try {
        $objPHPExcel = new PHPExcel();
        $objPHPExcel->setActiveSheetIndex(0);
        $objPHPExcel->getActiveSheet()
                ->getStyle('A1:S1')
                ->getFill()
                ->setFillType(PHPExcel_Style_Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('4e88be');
        $objPHPExcel->getActiveSheet()->getStyle('A1:S1')->getFont()->getColor()->setRGB('FFFFFF');

        foreach ($objPHPExcel->getWorksheetIterator() as $worksheet) {

            $objPHPExcel->setActiveSheetIndex($objPHPExcel->getIndex($worksheet));

            $sheet = $objPHPExcel->getActiveSheet();
            $cellIterator = $sheet->getRowIterator()->current()->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(true);
            /** @var PHPExcel_Cell $cell */
            foreach ($cellIterator as $cell) {
                $sheet->getColumnDimension($cell->getColumn())->setAutoSize(true);
            }
        }
        $objPHPExcel->getActiveSheet()->setAutoFilter('A1:S1');

        foreach ($excelData as $index => $data) {
            foreach ($data as $j => $column_data) {
                $objPHPExcel->getActiveSheet()->setCellValueByColumnAndRow($j, $index, $column_data);
            }
        }

        $fileType = 'Excel5';
        $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, $fileType);
        $objWriter->save($file['file_directory']);
            
    } catch (Exception $e) {
        die('Error loading file "' . pathinfo($inputFileName, PATHINFO_BASENAME)
                . '": ' . $e->getMessage());
    }

    $isActive = "Select count(emp.id) from employees as emp where emp.id = " . $bdmData[0] . " and emp.employment_status not in('r','q','b','o','t')";
    $is_active_emp = @mysql_query($isActive);
    $activeBdm = '';
    if ($is_active_emp) {
        while ($ccdata = mysql_fetch_array($is_active_emp)) {
            $activeBdm = $ccdata[0];
            break;
        }
    }
    if ($activeBdm == 1) {
        $email['to'] = $bdmData[2];
        $email['cc'] = $cc_mail['cc'];

        $email['subject'] = $file['file_name'];
        //Get email content
        $content = debtors_customer_wise($reportDate, $bdmData);

        if ($content != '') {
            if (in_array($email['to'], $email['cc'])) {
                $key = array_search($email['to'], $email['cc']);
                unset($email['cc'][$key]);
            }
            echo $content;
            sendMail1($content, $email, $file);
        } else {
            echo "No records for " . $bdmData[0];
        }
    }
}

function debtors_customer_wise($maxdate, $bdmData) {
    $extract_ym = date('Y-m', strtotime($maxdate));
    $base = strtotime(date('Y-m', time()) . '-01 00:00:01');
    $customer = "(SELECT distinct(Customer.customer_code) as customer_code FROM `invoices` AS `Invoice`
left JOIN projects AS `Projectalise` ON (`Invoice`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSoMaster` ON (`Invoice`.`project_id` = `ProjectSoMaster`.`project_id`)
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSoMaster`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `SaleOrder`.`customer_id`) 
WHERE `balance_amount` != 0  and SaleOrder.sales_person_id=" . $bdmData[0] . ")
                UNION 
                (SELECT distinct(customer_code) as customer_code FROM `non_ideal_invoices` AS `NonIdealInvoice` 
WHERE `balance_amount` != 0  and bdm_id=" . $bdmData[0] . ") "
            . "UNION"
            . " ( SELECT distinct(`Customer`.customer_code) as customer_code FROM `credit_notes` AS `CreditNote`
left JOIN credit_with_no_references AS `CreditWithNoReference` ON `CreditWithNoReference`.`credite_note_id` = `CreditNote`.`id`
left JOIN customers AS `Customer` ON (`Customer`.`id` = `CreditWithNoReference`.`customer_no`) 
WHERE `CreditNote`.`balance_amount` > 0 and `CreditNote`.deleted=0 and `CreditWithNoReference`.bdm_id =" . $bdmData[0] . ") "
            . "UNION"
            ."(SELECT distinct(Customer.customer_code) as customer_code
 FROM `invoices` AS `Invoice` 
 inner join invoice_consolidations as `InvoiceConsolidation` on (`Invoice`.`consolidated_id` = `InvoiceConsolidation`.`id`)
 left join invoice_consolidation_details as `InvoiceConsolidationDetail` on (`InvoiceConsolidation`.`id` = `InvoiceConsolidationDetail`.`invoice_consolidation_id`)
 left join invoices as `InvoiceSelf` on (`InvoiceSelf`.`id` = `InvoiceConsolidationDetail`.`invoice_id` )
left JOIN projects AS `Projectalise` ON (`InvoiceSelf`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSo` ON (`InvoiceSelf`.`project_id` = `ProjectSo`.`project_id`) 
left JOIN project_purchase_orders AS `ProjectPurchaseOrder` ON (`ProjectPurchaseOrder`.`id` = `InvoiceSelf`.`po_id`) 
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSo`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `SaleOrder`.`customer_id`) 
WHERE `Invoice`.`balance_amount` != 0  and SaleOrder.sales_person_id=" . $bdmData[0] . ")";

    $customer_list = mysql_query($customer);
    $customer_code_array = array();
    while ($customer_code = mysql_fetch_array($customer_list)) {
        $customer_code_array[] = $customer_code['customer_code'];
    }
    $customer_code_string = implode("','", $customer_code_array);

    if (empty($customer_code_array)) {
        return '';
    }
    $max_query = "SELECT max(DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) AS `max_credit_due_date` FROM invoices `Invoice`
            left JOIN projects AS `Projectalise` ON (`Invoice`.`project_id` = `Projectalise`.`id`) 
            left JOIN project_so_masters AS `ProjectSoMaster` ON (`Invoice`.`project_id` = `ProjectSoMaster`.`project_id`)
            left JOIN sales_orders AS `SaleOrder` ON (`ProjectSoMaster`.`sales_order_id` = `SaleOrder`.`id`) 
            WHERE `balance_amount` > 0 AND SaleOrder.sales_person_id=" . $bdmData[0];

    $max_due = @mysql_query($max_query);
    $max_due_date = array();
    $dr = array();
    while ($dr = mysql_fetch_array($max_due)) {
        $max_due_date[] = $dr['max_credit_due_date'];
    }
    $max_ConInvoice_query = "SELECT max(DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) AS `max_credit_due_date` FROM invoices `Invoice`
           inner join invoice_consolidations as `InvoiceConsolidation` on (`Invoice`.`consolidated_id` = `InvoiceConsolidation`.`id`)
 left join invoice_consolidation_details as `InvoiceConsolidationDetail` on (`InvoiceConsolidation`.`id` = `InvoiceConsolidationDetail`.`invoice_consolidation_id`)
 left join invoices as `InvoiceSelf` on (`InvoiceSelf`.`id` = `InvoiceConsolidationDetail`.`invoice_id` )
left JOIN projects AS `Projectalise` ON (`InvoiceSelf`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSo` ON (`InvoiceSelf`.`project_id` = `ProjectSo`.`project_id`) 
left JOIN project_purchase_orders AS `ProjectPurchaseOrder` ON (`ProjectPurchaseOrder`.`id` = `InvoiceSelf`.`po_id`) 
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSo`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `SaleOrder`.`customer_id`) 
            WHERE `balance_amount` > 0 AND SaleOrder.sales_person_id=" . $bdmData[0];

    $max_ConInv_due = @mysql_query($max_ConInvoice_query);
//    $max_due_date = array();
    $dr = array();
    while ($dr = mysql_fetch_array($max_ConInv_due)) {
        $max_due_date[] = $dr['max_credit_due_date'];
    }

    $max_query_non_ideal = "SELECT max(DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) AS `max_credit_due_date` 
            FROM non_ideal_invoices `NonIdealInvoice`
            WHERE `balance_amount` > 0 AND NonIdealInvoice.bdm_id=" . $bdmData[0];
    $max_due_non_ideal = @mysql_query($max_query_non_ideal);

    $dr = array();
    while ($dr = mysql_fetch_array($max_due_non_ideal)) {
        $max_due_date[] = $dr['max_credit_due_date'];
    }
    $max_customer_due_date = max($max_due_date);

    $month_count = 0;
    $customer_due_date_tms = strtotime($max_customer_due_date);
    $date_of_reort_tms = strtotime($maxdate);
    $not_due_query = '';
    $not_due_query_ni = '';
    $not_due_query_cr = '';
    $not_due_query_con = '';

        if($customer_due_date_tms > $date_of_reort_tms)
        {  
        $date1 = $maxdate;
        $date2 = $max_customer_due_date;
        $ts1 = strtotime($date1);
        $ts2 = strtotime($date2);
        $year1 = date('Y', $ts1);
        $year2 = date('Y', $ts2);
        $month1 = date('m', $ts1);
        $month2 = date('m', $ts2);
        $month_count = (($year2 - $year1) * 12) + ($month2 - $month1);

        for ($i = 1; $month_count >= $i; $i++) {
            if ($i != $month_count) {
                $not_due_query .= "sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'  then (Invoice.balance_amount) end) as '" . date('M-y', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "',";
                $not_due_query_con .= "sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'  then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "',";
                $not_due_query_ni .= "sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) = '" . date('Ym', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'  then (NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "',";
                $not_due_query_cr .= "sum(case when EXTRACT(YEAR_MONTH FROM (CreditNote.date)) = '" . date('Ym', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'  then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "',";
            } else {
                $not_due_query .= "sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'  then (Invoice.balance_amount) end) as '" . date('M-y', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'";
                $not_due_query_con .= "sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'  then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'";
                $not_due_query_ni .= "sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) = '" . date('Ym', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'  then (NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'";
                $not_due_query_cr .= "sum(case when EXTRACT(YEAR_MONTH FROM (CreditNote.date)) = '" . date('Ym', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'  then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("+" . $i . " months", strtotime($extract_ym, $base))) . "'";
            }
        }
    }
    $tod_date = date("Y-m-d");
    $summary = "(SELECT Customer.customer_name AS cust_name, Customer.customer_code AS customer_code,
                      `Currency`.currency_code as currency_code,
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) < '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (Invoice.balance_amount) end) as 'past" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (Invoice.balance_amount) end) as '" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-2 months", strtotime($extract_ym, $base))) . "' then (Invoice.balance_amount) end) as '" . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . "', 
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-1 months", strtotime($extract_ym, $base))) . "' then (Invoice.balance_amount) end) as '" . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day) >= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-01' and DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day) < '" . $tod_date . "' then (Invoice.balance_amount) end) as '" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "', 
                      sum(case when  DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day) >= '" . $tod_date . "' and DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day) <= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-31' then (Invoice.balance_amount) end) as 'no_due" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "'";
    if ($not_due_query != '') {
        $summary .= ",";
        $summary .= $not_due_query;
    }
    $summary .= " FROM invoices as Invoice 
left JOIN projects AS `Projectalise` ON (`Invoice`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSoMaster` ON (`Invoice`.`project_id` = `ProjectSoMaster`.`project_id`)
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSoMaster`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `SaleOrder`.`customer_id`) 
left JOIN currencies AS `Currency` ON (`Currency`.`id` = `SaleOrder`.`currency_id`) 
WHERE Invoice.balance_amount > 0 AND SaleOrder.sales_person_id = " . $bdmData[0] . " AND Invoice.status= 'a' AND
Customer.customer_code IN('" . $customer_code_string . "') 
GROUP BY Customer.customer_code, `Currency`.currency_code) order by Customer.customer_code";

    $summary_list = @mysql_query($summary);
    $summary_Con = "(SELECT Customer.customer_name AS cust_name, Customer.customer_code AS customer_code,
                      `Currency`.currency_code as currency_code,
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) < '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (InvoiceSelf.balance_amount) end) as 'past" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-2 months", strtotime($extract_ym, $base))) . "' then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . "', 
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-1 months", strtotime($extract_ym, $base))) . "' then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day) >= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-01' and DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day) < '" . $tod_date . "' then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "', 
                      sum(case when  DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day) >= '" . $tod_date . "' and DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day) <= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-31' then (InvoiceSelf.balance_amount) end) as 'no_due" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "'";
    if ($not_due_query_con != '') {
        $summary_Con .= ",";
        $summary_Con .= $not_due_query_con;
    }
    $summary_Con .= " FROM invoices as Invoice 
    inner join invoice_consolidations as `InvoiceConsolidation` on (`Invoice`.`consolidated_id` = `InvoiceConsolidation`.`id`)
 left join invoice_consolidation_details as `InvoiceConsolidationDetail` on (`InvoiceConsolidation`.`id` = `InvoiceConsolidationDetail`.`invoice_consolidation_id`)
 left join invoices as `InvoiceSelf` on (`InvoiceSelf`.`id` = `InvoiceConsolidationDetail`.`invoice_id` )
left JOIN projects AS `Projectalise` ON (`InvoiceSelf`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSo` ON (`InvoiceSelf`.`project_id` = `ProjectSo`.`project_id`) 
left JOIN project_purchase_orders AS `ProjectPurchaseOrder` ON (`ProjectPurchaseOrder`.`id` = `InvoiceSelf`.`po_id`) 
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSo`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `SaleOrder`.`customer_id`) 
left JOIN currencies AS `Currency` ON (`Currency`.`id` = `SaleOrder`.`currency_id`) 
WHERE  ((`Invoice`.`status` = 'a') and (`Invoice`.`deleted` = '0')   AND  (`Invoice`.`balance_amount` > 0) )  AND SaleOrder.sales_person_id = " . $bdmData[0] . " AND 
Customer.customer_code IN('" . $customer_code_string . "') 
GROUP BY Customer.customer_code, `Currency`.currency_code) order by Customer.customer_code";

    $summary_Con_list = @mysql_query($summary_Con);
    
//Non ideal starts here
    $summary_ni = " 
(SELECT c.customer_name AS cust_name, c.customer_code AS customer_code,
`Currency`.currency_code,
sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) < '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (NonIdealInvoice.balance_amount) end) as 'past" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) = '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) = '" . date('Ym', strtotime("-2 months", strtotime($extract_ym, $base))) . "' then (NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . "', 
sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) = '" . date('Ym', strtotime("-1 months", strtotime($extract_ym, $base))) . "' then (NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . "',
sum(case when  DATE_FORMAT(DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day),'%Y-%m-%d') >= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-01' and (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) < '" . $tod_date . "' then (NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "', 
sum(case when  DATE_FORMAT(DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day),'%Y-%m-%d') >= '" . $tod_date . "' and (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) <= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-31' then (NonIdealInvoice.balance_amount) end) as 'no_due" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "'";
    if ($not_due_query_ni != '') {
        $summary_ni .= ",";
        $summary_ni .= $not_due_query_ni;
    }
    $summary_ni .= " FROM non_ideal_invoices as NonIdealInvoice
    LEFT JOIN customers as c on NonIdealInvoice.customer_id = c.id 
    left JOIN currencies AS `Currency` ON (`Currency`.`id` = `NonIdealInvoice`.`invoicing_currency_id`) 
    WHERE NonIdealInvoice.balance_amount > 0 AND NonIdealInvoice.bdm_id = " . $bdmData[0] . " 
    AND c.customer_code IN('" . $customer_code_string . "') GROUP BY c.customer_code,`Currency`.currency_code) "
            . "order by c.customer_code";
    $summary_list_ni = mysql_query($summary_ni);

    $non_ideal = array();
    if ($summary_list_ni) {
        while ($summary_details_ni = mysql_fetch_array($summary_list_ni)) {

            $non_ideal[$summary_details_ni['customer_code'] . '-' . $summary_details_ni['currency_code']] = $summary_details_ni;
        }
    }

// Credit notes without reference
    $summary_cr = " 
(SELECT c.customer_name AS cust_name, c.customer_code AS customer_code,
`CreditWithNoReference`.currency as currency_code,
sum(case when  EXTRACT(YEAR_MONTH FROM (CreditNote.date)) < '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (CreditNote.balance_amount) end) as 'past" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
sum(case when  EXTRACT(YEAR_MONTH FROM (CreditNote.date)) = '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
sum(case when  EXTRACT(YEAR_MONTH FROM (CreditNote.date)) = '" . date('Ym', strtotime("-2 months", strtotime($extract_ym, $base))) . "' then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . "', 
sum(case when  EXTRACT(YEAR_MONTH FROM (CreditNote.date)) = '" . date('Ym', strtotime("-1 months", strtotime($extract_ym, $base))) . "' then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . "',
sum(case when  DATE_FORMAT(CreditNote.date,'%Y-%m-%d') >= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-01' and (CreditNote.date) < '" . $tod_date . "' then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "', 
sum(case when  DATE_FORMAT(CreditNote.date,'%Y-%m-%d') >= '" . $tod_date . "' and (CreditNote.date) <= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-31' then (CreditNote.balance_amount) end) as 'no_due" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "'";
    if ($not_due_query_cr != '') {
        $summary_cr .= ",";
        $summary_cr .= $not_due_query_cr;
    }
    $summary_cr .= " FROM `credit_notes` AS `CreditNote` 
    left JOIN credit_with_no_references AS `CreditWithNoReference` ON (`CreditWithNoReference`.`credite_note_id` = `CreditNote`.`id`) 
    LEFT JOIN customers as c on `CreditWithNoReference`.customer_no = c.id     
    WHERE CreditNote.balance_amount > 0 AND CreditWithNoReference.bdm_id = " . $bdmData[0] . " and CreditNote.deleted = 0 
    AND c.customer_code IN('" . $customer_code_string . "') GROUP BY c.customer_code,CreditWithNoReference.currency) 
    order by c.customer_code";

    $summary_list_cr = mysql_query($summary_cr);

    $cr = array();
    if ($summary_list_cr) {
        while ($summary_details_cr = mysql_fetch_array($summary_list_cr)) {
            $cr[$summary_details_cr['customer_code'] . '-' . $summary_details_cr['currency_code']] = $summary_details_cr;
        }
    }
// Credit notes

    $ideal_1 = array();
    if ($summary_list) {
        while ($summary_details_1 = mysql_fetch_array($summary_list)) {

            $ideal_1[$summary_details_1['customer_code'] . '-' . $summary_details_1['currency_code']] = $summary_details_1;
        }
    }
    // consolidated Invoice
    $ideal_Cons = array();
    if ($summary_Con_list) {
        while ($summary_details_Con = mysql_fetch_array($summary_Con_list)) {

            $ideal_Cons[$summary_details_Con['customer_code'] . '-' . $summary_details_Con['currency_code']] = $summary_details_Con;
        }
    }

    $tmp_cust = array();
    $tmp_cust = array_unique(array_merge(array_keys($non_ideal), array_keys($ideal_1), array_keys($cr), array_keys($ideal_Cons)));
//echo "<pre>";print_r($ideal_1);print_r($non_ideal);print_r($cr);echo "</pre>";
//Non ideal ends here

    $summary_list = @mysql_query($summary);
    $summary_html = '';
    $summary_html .= '
          <head>
            <style type="text/css">
                table {
                font-family: "Lato","sans-serif";
                border: 2px solid #cfdff1;}     
                table.one {                                 
                margin-bottom: 3em;
                   }  
                td {             
                padding: 4px;
                border: solid 1px #cfdff1;
                }      
                th {                             
                text-align: center;                
                padding: 4px;
                background-color: #4e88be;       
                color: white;
                border: solid 1px #cfdff1;}                
                tr {   
                height 4px;    }
                table tr:nth-child(even) {            
                background-color: #eee;     }
                table tr:nth-child(odd) {           
                background-color:#fff;      }
                
                
                </style>
          </head>
          <body style="margin: 10px;">
          <div style="width: 950px; font-family: Arial, Helvetica, sans-serif; font-size: 13px;">
          Dear <b></b>' . $bdmData[1] . ',<br><br>';
    $month_count_add = $month_count + 1;

    $total_summary = "SELECT Customer.customer_name AS cust_name,Customer.customer_code AS customer_code,
                             sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) < '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (ForexConversion.value * Invoice.balance_amount) end) as 'past" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                             sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (ForexConversion.value * Invoice.balance_amount) end) as '" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                             sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-2 months", strtotime($extract_ym, $base))) . "' then (ForexConversion.value * Invoice.balance_amount) end) as '" . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . "', 
                             sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-1 months", strtotime($extract_ym, $base))) . "' then (ForexConversion.value * Invoice.balance_amount) end) as '" . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . "',
                             sum(case when  (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) >= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-01' and (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) < '" . $tod_date . "' then (ForexConversion.value * Invoice.balance_amount) end) as '" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "', 
                             sum(case when  (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) >= '" . $tod_date . "' and (DATE_ADD(Invoice.invoice_date,INTERVAL SaleOrder.credit_period day)) <= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-31' then (ForexConversion.value * Invoice.balance_amount) end) as 'no_due" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "'";
    if ($not_due_query != '') {
        $total_summary .= ",";
        $total_summary .= $not_due_query;
    }
    $total_summary .= " FROM invoices as Invoice 
                    left JOIN projects AS `Projectalise` ON (`Invoice`.`project_id` = `Projectalise`.`id`) 
                    left JOIN project_so_masters AS `ProjectSo` ON (`Invoice`.`project_id` = `ProjectSo`.`project_id`) 
                    left JOIN sales_orders AS `SaleOrder` ON (`ProjectSo`.`sales_order_id` = `SaleOrder`.`id`) 
                    LEFT JOIN customers as Customer on SaleOrder.customer_id = Customer.id
                    left JOIN (select * from (select * from forex_conversions order by date desc) as new 
                    group by new.from_currency_id order by date desc) AS `ForexConversion` 
                    ON (`ForexConversion`.`from_currency_id` = `Projectalise`.`currency_id` and DATE_FORMAT(`ForexConversion`.`date`,'%Y-%m-%d') <= CURDATE())
                    WHERE Invoice.balance_amount > 0 AND SaleOrder.sales_person_id = " . $bdmData[0] . " AND 
                    Customer.customer_code IN('" . $customer_code_string . "') group by ''";
    $total_summary_list = mysql_query($total_summary);
    $total_summary_cons = "SELECT Customer.customer_name AS cust_name,Customer.customer_code AS customer_code,
                               sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) < '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (InvoiceSelf.balance_amount) end) as 'past" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-2 months", strtotime($extract_ym, $base))) . "' then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . "', 
                      sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day))) = '" . date('Ym', strtotime("-1 months", strtotime($extract_ym, $base))) . "' then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . "',
                      sum(case when  DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day) >= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-01' and DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day) < '" . $tod_date . "' then (InvoiceSelf.balance_amount) end) as '" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "', 
                      sum(case when  DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day) >= '" . $tod_date . "' and DATE_ADD(InvoiceSelf.invoice_date,INTERVAL SaleOrder.credit_period day) <= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-31' then (InvoiceSelf.balance_amount) end) as 'no_due" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "'";
      if ($not_due_query_con != '') {
        $total_summary_cons .= ",";
        $total_summary_cons .= $not_due_query_con;
    }
    $total_summary_cons .= " FROM invoices as Invoice 
                    inner join invoice_consolidations as `InvoiceConsolidation` on (`Invoice`.`consolidated_id` = `InvoiceConsolidation`.`id`)
 left join invoice_consolidation_details as `InvoiceConsolidationDetail` on (`InvoiceConsolidation`.`id` = `InvoiceConsolidationDetail`.`invoice_consolidation_id`)
 left join invoices as `InvoiceSelf` on (`InvoiceSelf`.`id` = `InvoiceConsolidationDetail`.`invoice_id` )
left JOIN projects AS `Projectalise` ON (`InvoiceSelf`.`project_id` = `Projectalise`.`id`) 
left JOIN project_so_masters AS `ProjectSo` ON (`InvoiceSelf`.`project_id` = `ProjectSo`.`project_id`) 
left JOIN project_purchase_orders AS `ProjectPurchaseOrder` ON (`ProjectPurchaseOrder`.`id` = `InvoiceSelf`.`po_id`) 
left JOIN sales_orders AS `SaleOrder` ON (`ProjectSo`.`sales_order_id` = `SaleOrder`.`id`) 
left JOIN customers AS `Customer` ON (`Customer`.`id` = `SaleOrder`.`customer_id`) 
                    left JOIN (select * from (select * from forex_conversions order by date desc) as new 
                    group by new.from_currency_id order by date desc) AS `ForexConversion` 
                    ON (`ForexConversion`.`from_currency_id` = `Projectalise`.`currency_id` and DATE_FORMAT(`ForexConversion`.`date`,'%Y-%m-%d') <= CURDATE())
                    WHERE Invoice.balance_amount > 0 AND SaleOrder.sales_person_id = " . $bdmData[0] . " AND 
                    Customer.customer_code IN('" . $customer_code_string . "') group by ''";
    $total_summary_con_list = mysql_query($total_summary_cons);

    $total_summary_ni = "SELECT Customer.customer_name AS cust_name, Customer.customer_code,
                             sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) < '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (ForexConversion.value * NonIdealInvoice.balance_amount) end) as 'past" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                             sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) = '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (ForexConversion.value * NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
                             sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) = '" . date('Ym', strtotime("-2 months", strtotime($extract_ym, $base))) . "' then (ForexConversion.value * NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . "', 
                             sum(case when  EXTRACT(YEAR_MONTH FROM (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day))) = '" . date('Ym', strtotime("-1 months", strtotime($extract_ym, $base))) . "' then (ForexConversion.value * NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . "',
                             sum(case when  (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) >= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-01' and (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) < '" . $tod_date . "' then (ForexConversion.value * NonIdealInvoice.balance_amount) end) as '" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "', 
                             sum(case when  (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) >= '" . $tod_date . "' and (DATE_ADD(NonIdealInvoice.invoice_date,INTERVAL NonIdealInvoice.credit_period day)) <= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-31' then (ForexConversion.value * NonIdealInvoice.balance_amount) end) as 'no_due" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "'";
    if ($not_due_query_ni != '') {
        $total_summary_ni .= ",";
        $total_summary_ni .= $not_due_query_ni;
    }
    $total_summary_ni .= " FROM non_ideal_invoices as NonIdealInvoice 
                    LEFT JOIN customers as Customer on NonIdealInvoice.customer_id = Customer.id
                    left JOIN (select * from (select * from forex_conversions order by date desc) as new 
                    group by new.from_currency_id order by date desc) AS `ForexConversion` 
                    ON (`ForexConversion`.`from_currency_id` = `NonIdealInvoice`.`invoicing_currency_id` and 
                    DATE_FORMAT(`ForexConversion`.`date`,'%Y-%m-%d') <= CURDATE())
                    WHERE NonIdealInvoice.balance_amount > 0 AND NonIdealInvoice.bdm_id = " . $bdmData[0] . " AND 
                    Customer.customer_code IN('" . $customer_code_string . "') group by ''";

    $total_summary_ni_list = mysql_query($total_summary_ni);

    $total_summary_cr = " 
SELECT c.customer_name AS cust_name, c.customer_code AS customer_code,
`CreditWithNoReference`.currency as currency_code,
sum(case when  EXTRACT(YEAR_MONTH FROM (CreditNote.date)) < '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (CreditNote.balance_amount) end) as 'past" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
sum(case when  EXTRACT(YEAR_MONTH FROM (CreditNote.date)) = '" . date('Ym', strtotime("-3 months", strtotime($extract_ym, $base))) . "' then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . "',
sum(case when  EXTRACT(YEAR_MONTH FROM (CreditNote.date)) = '" . date('Ym', strtotime("-2 months", strtotime($extract_ym, $base))) . "' then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . "', 
sum(case when  EXTRACT(YEAR_MONTH FROM (CreditNote.date)) = '" . date('Ym', strtotime("-1 months", strtotime($extract_ym, $base))) . "' then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . "',
sum(case when  DATE_FORMAT(CreditNote.date,'%Y-%m-%d') >= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-01' and (CreditNote.date) < '" . $tod_date . "' then (CreditNote.balance_amount) end) as '" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "', 
sum(case when  DATE_FORMAT(CreditNote.date,'%Y-%m-%d') >= '" . $tod_date . "' and (CreditNote.date) <= '" . date('Y-m', strtotime("0 months", strtotime($extract_ym, $base))) . "-31' then (CreditNote.balance_amount) end) as 'no_due" . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . "'";
    if ($not_due_query_cr != '') {
        $total_summary_cr .= ",";
        $total_summary_cr .= $not_due_query_cr;
    }
    $total_summary_cr .= " FROM `credit_notes` AS `CreditNote` 
    left JOIN credit_with_no_references AS `CreditWithNoReference` ON (`CreditWithNoReference`.`credite_note_id` = `CreditNote`.`id`) 
    LEFT JOIN customers as c on `CreditWithNoReference`.customer_no = c.id     
    WHERE CreditNote.balance_amount > 0 AND CreditWithNoReference.bdm_id = " . $bdmData[0] . " and CreditNote.deleted = 0 
    AND c.customer_code IN('" . $customer_code_string . "') group by ''";
   
    $total_summary_cr_list = mysql_query($total_summary_cr);

    $total_non_ideal_tmp = array();
    $total_ideal_con_tmp = array();
    $total_ideal_tmp = array();
    $total_cr_tmp = array();
    while ($total_summary_ni_res = mysql_fetch_array($total_summary_ni_list)) {
        $total_non_ideal_tmp = $total_summary_ni_res;
    }
    while ($total_summary_details = mysql_fetch_array($total_summary_list)) {
        $total_ideal_tmp = $total_summary_details;
    }
    while ($total_summary_details_con = mysql_fetch_array($total_summary_con_list)) {
        $total_ideal_con_tmp = $total_summary_details_con;
    }

    while ($total_summary_cr_res = mysql_fetch_array($total_summary_cr_list)) {
        $total_cr_tmp = $total_summary_cr_res;
    }

    $colspan = 0;
    $colspan_no_due = 0;
    $tmp1 = isset($total_non_ideal_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_non_ideal_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $tmp2 = isset($total_ideal_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_ideal_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $tmp4 = isset($total_ideal_con_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_ideal_con_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $tmp3 = isset($total_cr_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_cr_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $no_due_current = $tmp1 + $tmp2+$tmp4+$tmp3;

    if ($no_due_current > 0) {
        $colspan_no_due++;
    }

    $null_data = array();
    $ni_data_1 = isset($total_non_ideal_tmp['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $total_non_ideal_tmp['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : 0;
    $i_data_1 = isset($total_ideal_tmp['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $total_ideal_tmp['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : 0;
    $con_data_1 = isset($total_ideal_con_tmp['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $total_ideal_con_tmp['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : 0;
    $cr_data_1 = isset($total_cr_tmp['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $total_cr_tmp['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : 0;
    $tot_1_tmp = formatMoney($i_data_1 + $ni_data_1 + $cr_data_1 + $con_data_1);

    $ni_data_2 = isset($total_non_ideal_tmp[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $total_non_ideal_tmp[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : 0;
    $i_data_2 = isset($total_ideal_tmp[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $total_ideal_tmp[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : 0;
    $con_data_2 = isset($total_ideal_con_tmp[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $total_ideal_con_tmp[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : 0;
    $cr_data_2 = isset($total_cr_tmp[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $total_cr_tmp[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : 0;
    $tot_2_tmp = formatMoney($i_data_2 + $ni_data_2 + $cr_data_2 + $con_data_2);

    $ni_data_3 = isset($total_non_ideal_tmp[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))]) ? $total_non_ideal_tmp[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))] : 0;
    $i_data_3 = isset($total_ideal_tmp[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))]) ? $total_ideal_tmp[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))] : 0;
    $con_data_3 = isset($total_ideal_con_tmp[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))]) ? $total_ideal_con_tmp[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))] : 0;
    $cr_data_3 = isset($total_cr_tmp[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))]) ? $total_cr_tmp[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))] : 0;
    $tot_3_tmp = formatMoney($i_data_3 + $ni_data_3 + $cr_data_3+$con_data_3);

    $ni_data_4 = isset($total_non_ideal_tmp[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))]) ? $total_non_ideal_tmp[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))] : 0;
    $i_data_4 = isset($total_ideal_tmp[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))]) ? $total_ideal_tmp[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))] : 0;
    $con_data_4 = isset($total_ideal_con_tmp[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))]) ? $total_ideal_con_tmp[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))] : 0;
    $cr_data_4 = isset($total_cr_tmp[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))]) ? $total_cr_tmp[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))] : 0;
    $tot_4_tmp = formatMoney($i_data_4 + $ni_data_4 + $cr_data_4 + $con_data_4);

    $ni_data_5 = isset($total_non_ideal_tmp[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_non_ideal_tmp[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $i_data_5 = isset($total_ideal_tmp[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_ideal_tmp[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $con_data_5 = isset($total_ideal_con_tmp[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_ideal_con_tmp[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $cr_data_5 = isset($total_cr_tmp[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_cr_tmp[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $tot_5_tmp = formatMoney($i_data_5 + $ni_data_5 + $cr_data_5 + $con_data_5);

    $ni_data_6 = isset($total_non_ideal_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_non_ideal_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $i_data_6 = isset($total_ideal_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_ideal_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $con_data_6 = isset($total_ideal_con_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_ideal_con_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $cr_data_6 = isset($total_cr_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $total_cr_tmp['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : 0;
    $tot_6_tmp = formatMoney($i_data_6 + $ni_data_6 + $cr_data_6 + $con_data_6);

    $tot_no_due_fut_tmp = array();
    for ($i = 1; $month_count >= $i; $i++) {

        $tmp_no_due_ni = isset($total_non_ideal_tmp[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $total_non_ideal_tmp[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : 0;
        $tmp_no_due_i = isset($total_ideal_tmp[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $total_ideal_tmp[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : 0;
        $tmp_no_due_con = isset($total_ideal_con_tmp[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $total_ideal_con_tmp[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : 0;
        $tmp_no_due_cr = isset($total_cr_tmp[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $total_cr_tmp[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : 0;
        $tot_no_due_fut_tmp[$i] = formatMoney($tmp_no_due_ni + $tmp_no_due_i + $tmp_no_due_cr + $tmp_no_due_con);
            
    }

    for ($i = 1; $month_count >= $i; $i++) {
        if ($tot_no_due_fut_tmp[$i] != '') {
            $colspan_no_due++;
        }
    }

    $tot_due = $tot_1_tmp + $tot_2_tmp + $tot_3_tmp + $tot_4_tmp + $tot_5_tmp;

    $summary_html .= 'Please find enclosed summary of outstanding debtors as on date. ';
    $summary_html .= 'Request you to review and follow up with the customer on earliest possible collections, also update the <b>Invoice Submission Date</b> and <b>Expected Collection Date</b> in iDEAL tool.<br><br>
             Soft copy of the Invoices available for download in iDEAL. Path>> Project & Time Sheet>> Accrual & Invoicing >> Invoice List - BDM.
             <br><br>In case of any clarifications, please reach out AR@Hindujatech.com<br><br>';
    $summary_html .= '<table cellpadding="0" cellspacing="0" border="1" style="border: solid #cfdff1 1.5pt;">
                        <tr nowrap style="text-align: center;padding: 4px;background-color: #4e88be;color: white;
                        border: solid 1px #cfdff1;">
                                <th></th>';
    $summary_html .= '<th></th>';
    if ($tot_due > 0) {
        $summary_html .= '<th class="cls_due" colspan="5">Due</th>';
    }

    if ($colspan_no_due > 0) {
        $summary_html .= '<th class="cls_no_due" colspan=' . $colspan_no_due . '>Not Due</th>';
    }
    $summary_html .= '<th></th>
                            </tr>
                            <tr nowrap style="text-align: center;                
                        padding: 4px;
                        background-color: #4e88be;       
                        color: white;
                        border: solid 1px #cfdff1;">
                        <th> Customer Name </th>
                        <th> Invoicing Currency </th>';

    if ($tot_1_tmp != '') {
        $null_data[0] = '<th class="col1"> Prior to ' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . ' </th>';
        $summary_html .= $null_data[0];
        $colspan++;
    }

    if ($tot_2_tmp != '') {
        $null_data[1] = '<th class="col2"> ' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base))) . ' </th>';
        $summary_html .= $null_data[1];
        $colspan++;
    }

    if ($tot_3_tmp != '') {
        $null_data[2] = '<th class="col3"> ' . date('M-y', strtotime("-2 months", strtotime($extract_ym, $base))) . ' </th>';
        $summary_html .= $null_data[2];
        $colspan++;

    }
    if ($tot_4_tmp != '') {
        $null_data[3] = '<th class="col4"> ' . date('M-y', strtotime("-1 months", strtotime($extract_ym, $base))) . ' </th>';
        $summary_html .= $null_data[3];
        $colspan++;
    }

    if ($tot_5_tmp != '') {
        $summary_html .= '<th class="col5"> ' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . ' </th>';
        $colspan++;
    }

    if ($tot_6_tmp != '') {
        $summary_html .= '<th class="col6"> ' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base))) . ' </th>';
        $colspan_no_due++;
    }
    $p = 7;

    for ($i = 1; $month_count >= $i; $i++) {
        if ($tot_no_due_fut_tmp[$i] != '') {
            $summary_html .= '<th class="col' . $p . '"> ' . date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base))) . ' </th>';
            $colspan_no_due++;
            $p++;
        }
    }

    $summary_html .= '<th> Grand Total </th></tr>';

    $start = strpos($summary_html, 'cls_due');
    $start1 = strpos($summary_html, 'colspan=', $start);
    $summary_html = preg_replace('/colspan="5"/', 'colspan="' . $colspan . '"', $summary_html, 1);
    $summary_html = preg_replace('/colspan="([0-9])"/', 'colspan=' . $colspan, $summary_html, 1); // will replace first 'abc'

    $sorting_map = '';
    $total_array = array();
    $cnt_ideal = mysql_num_rows($summary_list);
    $cnt_non_ideal = mysql_num_rows($summary_list_ni);

        while ($summary_details = mysql_fetch_array($summary_list)) {
        $currency_code = $summary_details['currency_code'];
        $key1 = $summary_details['customer_code'] . '-' . $currency_code;
        $ideal_arr[$key1] = $summary_details;
    }

    $k1 = isset($ideal_arr) ? array_keys($ideal_arr) : array();
    $k2 = isset($non_ideal) ? array_keys($non_ideal) : array();
    $k4 = isset($ideal_Cons) ? array_keys($ideal_Cons) : array();
    $k3 = isset($cr) ? array_keys($cr) : array();
    $cust_keys = array_unique(array_merge($k1, $k2, $k3,$k4));
    $summary_sorting = '';
    if (empty($cust_keys)) {
        return;
    }
    foreach ($cust_keys as $key1) {

        $non_ideal_data = isset($non_ideal[$key1]) ? $non_ideal[$key1] : '';
        $ideal_data = isset($ideal_arr[$key1]) ? $ideal_arr[$key1] : '';
        $cr_data = isset($cr[$key1]) ? $cr[$key1] : '';
        $ideal_data_con = isset($ideal_Cons[$key1]) ? $ideal_Cons[$key1] : '';

        $customer_code = $summary_details['customer_code'];
        $customer_name = $summary_details['cust_name'];

        if (isset($non_ideal[$key1])) {
            $cust_disp = $non_ideal_data['customer_code'] . ' - ' . $non_ideal_data['cust_name'];
            $currency_code = $non_ideal_data['currency_code'];
        } else if (isset($ideal_data['currency_code'])) {
            $cust_disp = $ideal_data['customer_code'] . ' - ' . $ideal_data['cust_name'];
            $currency_code = $ideal_data['currency_code'];
        
        } else if (isset($ideal_data_con['currency_code'])) {
            $cust_disp = $ideal_data_con['customer_code'] . ' - ' . $ideal_data_con['cust_name'];
            $currency_code = $ideal_data_con['currency_code'];
        } else {
            $cust_disp = $cr_data['customer_code'] . ' - ' . $cr_data['cust_name'];
            $currency_code = $cr_data['currency_code'];
        }
        $summary_sorting .= '<tr nowrap><td> ' . $cust_disp . ' </td>';
        $summary_sorting .= '<td> ' . $currency_code . ' </td>';

        if ($tot_1_tmp != '') {
            $ni_past3m = isset($non_ideal_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $non_ideal_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : '';
            $cr_past3m = isset($cr_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $cr_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : '';
            $i_past3m = isset($ideal_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $ideal_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : '';
            $con_past3m = isset($ideal_data_con['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $ideal_data_con['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : '';
            $past_3m_tot = formatMoney(($con_past3m +$i_past3m + $ni_past3m - $cr_past3m), $currency_code);
            $summary_sorting .= '<td style="text-align: right;" class="col1"> ' . $past_3m_tot . ' </td>';
        }
        if ($tot_2_tmp != '') {
            $i_3m = isset($ideal_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $ideal_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : '';
            $con_3m = isset($ideal_data_con[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $ideal_data_con[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : '';
            $ni_3m = isset($non_ideal_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $non_ideal_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : '';
            $cr_3m = isset($cr_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))]) ? $cr_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))] : '';
            $t_3m = formatMoney(($con_3m + $i_3m + $ni_3m - $cr_3m), $currency_code);
            $summary_sorting .= '<td style="text-align: right;" class="col2"> ' . $t_3m . ' </td>';
        }
        if ($tot_3_tmp != '') {
            $i_2m = isset($ideal_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))]) ? $ideal_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))] : '';
            $con_2m = isset($ideal_data_con[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))]) ? $ideal_data_con[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))] : '';
            $ni_2m = isset($non_ideal_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))]) ? $non_ideal_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))] : '';
            $cr_2m = isset($cr_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))]) ? $cr_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))] : '';
            $t_2m = formatMoney(($con_2m + $i_2m + $ni_2m - $cr_2m), $currency_code);
            $summary_sorting .= '<td style="text-align: right;" class="col3" > ' . $t_2m . ' </td>';
        }
        if ($tot_4_tmp != '') {
            $i_1m = isset($ideal_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))]) ? $ideal_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))] : '';
            $con_1m = isset($ideal_data_con[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))]) ? $ideal_data_con[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))] : '';
            $ni_1m = isset($non_ideal_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))]) ? $non_ideal_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))] : '';
            $cr_1m = isset($cr_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))]) ? $cr_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))] : '';
            $t_1m = formatMoney(($con_1m + $i_1m + $ni_1m - $cr_1m), $currency_code);
            $summary_sorting .= '<td style="text-align: right;" class="col4"> ' . $t_1m . ' </td>';
        }
        if ($tot_5_tmp != '') {
            $i_0m = isset($ideal_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $ideal_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : '';
            $con_0m = isset($ideal_data_con[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $ideal_data_con[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : '';
            $ni_0m = isset($non_ideal_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $non_ideal_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : '';
            $cr_0m = isset($cr_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $cr[$key1][date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : '';
            $t_0m = formatMoney(($con_0m + $i_0m + $ni_0m - $cr_0m), $currency_code);
            $summary_sorting .= '<td style="text-align: right;" class="col5"> ' . $t_0m . ' </td>';
        }
        if ($tot_6_tmp != '') {
            $i_no_due = isset($ideal_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $ideal_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : '';
            $con_no_due = isset($ideal_data_con['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $ideal_data_con['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : '';
            $ni_no_due = isset($non_ideal_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $non_ideal_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : '';
            $cr_no_due = isset($cr_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))]) ? $cr_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))] : '';
            $t_no_due = formatMoney(($con_no_due + $i_no_due + $ni_no_due - $cr_no_due), $currency_code);
            $summary_sorting .= '<td style="text-align: right;" class="col6"> ' . $t_no_due . ' </td>';
        }

        for ($i = 1; $month_count >= $i; $i++) {

            if ($tot_no_due_fut_tmp[$i] != '') {
                $p = $i + 6;
                $i_no_due_1 = isset($ideal_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $ideal_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : '';
                $con_no_due_1 = isset($ideal_data_con[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $ideal_data_con[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : '';
                $ni_no_due_1 = isset($non_ideal_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $non_ideal_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : '';
                $cr_no_due_1 = isset($cr_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $cr_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : '';
                $t_no_due_fut = formatMoney(($i_no_due_1 + $ni_no_due_1 + $cr_no_due_1+$con_no_due_1), $currency_code);
                $summary_sorting .= '<td style="text-align: right;" class="col' . $p . '"> ' . $t_no_due_fut . ' </td>';
            }
        }

        $t = 0;
        if (isset($ideal_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data_con['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data_con['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data_con[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data_con[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data_con[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data_con[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data_con[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data_con[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data_con[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data_con[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))];
        }
        if (isset($ideal_data_con['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))])) {
            $t += $ideal_data_con['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))];
        }

        if (isset($non_ideal_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))])) {
            $t += $non_ideal_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))];
        }
        if (isset($non_ideal_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))])) {
            $t += $non_ideal_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))];
        }
        if (isset($non_ideal_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))])) {
            $t += $non_ideal_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))];
        }
        if (isset($non_ideal_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))])) {
            $t += $non_ideal_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))];
        }
        if (isset($non_ideal_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))])) {
            $t += $non_ideal_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))];
        }
        if (isset($non_ideal_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))])) {
            $t += $non_ideal_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))];
        }
        $t_cr = 0;
        if (isset($cr_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))])) {
            $t_cr += $cr_data['past' . date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))];
        }
        if (isset($cr_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))])) {
            $t_cr += $cr_data[date('M-y', strtotime("-3 months", strtotime($extract_ym, $base)))];
        }
        if (isset($cr_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))])) {
            $t_cr += $cr_data[date('M-y', strtotime("-2 months", strtotime($extract_ym, $base)))];
        }
        if (isset($cr_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))])) {
            $t_cr += $cr_data[date('M-y', strtotime("-1 months", strtotime($extract_ym, $base)))];
        }
        if (isset($cr_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))])) {
            $t_cr += $cr_data[date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))];
        }
        if (isset($cr_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))])) {
            $t_cr += $cr_data['no_due' . date('M-y', strtotime("0 months", strtotime($extract_ym, $base)))];
        }
        $t = $t - $t_cr;
        $nodue_total = 0;
        $nodue_total_con =0;
        $nodue_total_cr = 0;
        for ($i = 1; $month_count >= $i; $i++) {
            if (isset($non_ideal_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))])) {
                $nodue_total += $non_ideal_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))];
            }
            $nodue_total_con += isset($ideal_data_con[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $ideal_data_con[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : 0;
            $nodue_total += isset($ideal_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $ideal_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : 0;
            $nodue_total_cr += isset($cr_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))]) ? $cr_data[date('M-y', strtotime('+' . $i . ' months', strtotime($extract_ym, $base)))] : 0;
        }
        $t += $nodue_total_con;
        $t += $nodue_total;
        $t = $t - $nodue_total_cr;

        $summary_sorting .= '<td style="text-align: right;                
            padding: 4px;
            background-color: #4e88be;       
            color: white;
            border: solid 1px #cfdff1;"> ' . formatMoney($t, $currency_code) . ' </td>';
    }
    $summary_html .= $summary_sorting;
    $summary_html .= '</table></div><br><br>';
    $summary_html .= "<br><br>Regards,<br>Abdul Razak<br>(AR Department)<br></div></body>";
    return $summary_html;
}

function sendMail1($content, $email, $file) {

    $error = $error_data = '';
    try {
        $email_config_values = emailConfigurationAr();
        $mail = new PHPMailer(true);
        $mail->IsSMTP();

        $mail->SMTPDebug = 2;
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = "tls";
        $mail->Host = $email_config_values['host'];
        $mail->Port = $email_config_values['port'];
        $mail->Username = $email_config_values['ar_username'];
        $mail->Password = $email_config_values['ar_password'];
        $mail->From = $email_config_values['ar_from'];
        $mail->Sender = $email_config_values['ar_from'];
        $mail->SetFrom($email_config_values['ar_from'], 'Accounts Receivable');
        $mail->AddAttachment($file['file_directory']);

        if (isset($email['to'])) {
            $mail->AddAddress($email['to']);
        }

        if (isset($email['cc'])) {
            foreach ($email['cc'] as $key => $emailAdd) {
                $mail->addcc($emailAdd, $key);
            }
        }

        $bcc_email = array();
        if (isset($email_config_values['debtors_bdm_cron_bcc'])) {
            $bcc_email = explode(',', $email_config_values['debtors_bdm_cron_bcc']);
            foreach ($bcc_email as $value) {
                $mail->addBCC($value);
            }
        }

        $mail->Subject = $email['subject'];
        $mail->AltBody = 'To view the message, please use an HTML compatible email viewer!';
        $mail->MsgHTML($content);

        if (isset($email['to'])) {
          $result = $mail->Send();
            if (!$result) {
                $error .= "Mail not sent";
                echo "mail not sent";
            } else {
                echo "mail sent";
                return true;
            }
        }
        
    } catch (phpmailerException $e) {
        $error .= "Mail not sent, PHP Mailer Exception";
        $error_data .= $e->errorMessage();
    } catch (Exception $e) {
        $error .= "Mail not sent, Exception";
        $error_data .= $e->getMessage();
    }

    if ($error != '') {
        $err_query = "Insert into error_log (error_timestamp, error_code, error_description, module_name, 
            function_name, emp_id, data) values (now(), '', '" . $error . "', 'Debtors', 'BDM cron', '0', '" . strip_tags(trim($error_data)) . "')";

        @mysql_query($err_query);
        return false;
    }
}

function convert_date_format($date) {
    $timestamp = strtotime($date);
    $date_format = date('d-M-Y', $timestamp);
    return $date_format;
}

function emailConfigurationAr() {

    $sql = 'SELECT ConfigurationValues.configuration_key, ConfigurationValues.configuration_value 
		FROM configuration_values AS ConfigurationValues 
		INNER JOIN configuration_values AS a 
			ON (ConfigurationValues.parent_id = a.id) 
		WHERE a.configuration_key = "ar_mail_config" ';

    $result = mysql_query($sql);
    $email_config_values = array();

    while ($row = mysql_fetch_array($result)) {
        $email_config_values[$row[0]] = $row[1];
    }

    $sql_email = 'SELECT ConfigurationValues.configuration_key, ConfigurationValues.configuration_value 
		FROM configuration_values AS ConfigurationValues 
		INNER JOIN configuration_values AS a 
			ON (ConfigurationValues.parent_id = a.id) 
		WHERE a.configuration_key = "mail_details" and ConfigurationValues.configuration_key in ("host","port")';

    $result_email = mysql_query($sql_email);
    $email_config_values_host = array();

    while ($row1 = mysql_fetch_array($result_email)) {
        $email_config_values[$row1[0]] = $row1[1];
    }

    return $email_config_values;
}
?>