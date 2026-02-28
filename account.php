<?php

require_once 'account.civix.php';

use CRM_Account_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function account_civicrm_config(&$config): void {
  _account_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function account_civicrm_install(): void {
  _account_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function account_civicrm_enable(): void {
  _account_civix_civicrm_enable();
}

/**
 * This example compares the submitted value of a field with its current value.
 *
 * @param string $op
 *   The type of operation being performed.
 * @param int $groupID
 *   The custom group ID.
 * @param int $entityID
 *   The entityID of the row in the custom table.
 * @param array $params
 *   The parameters that were sent into the calling function.
 */

function account_civicrm_custom($op, $groupID, $entityID, &$params) {

	$extdebug		= 3; 	// 	1 = basic // 2 = verbose // 3 = params / 4 = results
	$apidebug		= FALSE;

	$profileprivacy = array(286);

	if (!in_array($groupID, $profileprivacy)) { // PROFILE PRIVACY
//		wachthond($extdebug,4, "########################################################################");
//		wachthond($extdebug,4, "EXIT: groupID ($groupID) != profileprivacy",          "($profileprivacy)");
//		wachthond($extdebug,4, "########################################################################");
		return; //	if not, get out of here
	}

	if ($op != 'create' && $op != 'edit') { //    did we just create or edit a custom object?
//		wachthond($extdebug,4, "########################################################################");
//		wachthond($extdebug,4, "EXIT: OP != CREATE OR OP != EDIT",                            "(OP: $OP)");
//		wachthond($extdebug,4, "########################################################################");
		return; //	if not, get out of here
	}

	$contact_id = $entityID;

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,2, "### ACCOUNT CUSTOM - CONFIGURE ONETIMELINK FOR $entityID", "[groupID: $groupID]");
	wachthond($extdebug,3, "########################################################################");

	wachthond($extdebug,4, "entityid",		$entityID);
	wachthond($extdebug,4, "params",		$params);

	account_civicrm_configure($contact_id, $groupID);

}

function account_civicrm_configure($contactid, $grouoID = NULL) {

	// --- 0. DEURWACHTER (SYSTEM LOCK) ---
    // Dit voorkomt dubbele mails als CiviCRM meerdere processen tegelijk start
    // (bijv. Webform submit + Hook trigger). Dit werkt over processen heen.
    
    if (!empty($contactid)) {
        $lock_key = 'account_config_lock_' . $contactid;
        
        // 1. Check of er al een slotje op zit
        if (Civi::cache()->get($lock_key)) {
            // Ja: Iemand is al bezig. Stop direct.
            // (Zet extdebug op 0 of 1 als je dit niet in je log wilt zien)
            if (function_exists('wachthond')) {
                wachthond(1, 1, "ACCOUNT CONFIG", "SKIPPED (Locked by Cache - prevent duplicate)");
            }
            return;
        }

        // 2. Nee: Zet een slotje voor 10 seconden
        // Dit is lang genoeg om het proces af te maken, maar kort genoeg 
        // zodat de gebruiker na 10 sec weer een nieuwe poging kan doen.
        Civi::cache()->set($lock_key, 1, 10);
    }
    
    // --- EINDE DEURWACHTER ---

/*
	// --- CRUCIAAL: RECURSIE STOP (DE PING-PONG STOPPER) ---
    static $processing_account = [];
    
    if (!empty($contactid)) {
        if (isset($processing_account[$contactid])) {
            // We zijn al bezig met dit specifieke account in dit request. Stop!
            return; 
        }
        $processing_account[$contactid] = true;
    }
*/
	$extdebug			= 3; 	// 	1 = basic // 2 = verbose // 3 = params / 4 = results
	$apidebug			= FALSE;

	// START TIMER
    if (function_exists('core_microtimer')) {
        watchdog('civicrm_timing', core_microtimer("START ACCOUNT CONFIG voor $displayname (ID: $contactid)"), NULL, WATCHDOG_DEBUG);
    }

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,2, "### ACCOUNT CONFIG - CONFIGURE ACCOUNT FOR $contactid", 	    "[START]");
	wachthond($extdebug,3, "########################################################################");

 	$today_datetime        	= date("Y-m-d H:i:s");
    $today_datetime_past    = date('Y-m-d H:i:s', strtotime('-50 year', strtotime($today_datetime)) );

	$crm_contactid 			= $contactid;

	$new_onetimelink_array 	= NULL;
	$new_checksum_array 	= NULL;

	$params_contact = [
		'checkPermissions' => FALSE,
		'debug' 	=> $apidebug,
 		'limit' 	=> 1,
		'select' 	=> [
  			'id',
  			'contact_id',
  			'birth_date', 
  			'email.email',
  			'display_name',
  			'job_title',
  			'external_identifier',

  			'WERVING.nextkamp_rondjaren',
  			'WERVING.nextkamp_decimalen',

  			'PRIVACY.onetimelink_aanvraag',
  			'PRIVACY.onetimelink_url',
  			'PRIVACY.onetimelink_date',
  			'PRIVACY.checksum',
  			'PRIVACY.checksum_date',
  			'PRIVACY.is_test',
		],
		'join' => [
  			['Email AS email', 'LEFT', ['id', '=', 'email.contact_id']],
		],
		'where' => [
  			['id', '=', $crm_contactid],
		],
	];

	wachthond($extdebug,7, 'params_continfo', 								$params_contact);
	if ($crm_contactid) { $result_contact = civicrm_api4('Contact','get',	$params_contact); }
	wachthond($extdebug,9, 'result_contact', 								$result_contact);

	if ($result_contact[0]['display_name']) 	   	{ $displayname		= ucfirst(trim($result_contact[0]['display_name']));	}
	if ($result_contact[0]['birth_date']) 	   		{ $birth_date		= trim($result_contact[0]['birth_date']);				}
	if ($result_contact[0]['job_title']) 		   	{ $crm_drupalnaam 	= trim($result_contact[0]['job_title']);		    	}
	if ($result_contact[0]['external_identifier']) 	{ $crm_drupalid	 	= trim($result_contact[0]['external_identifier']);		}

	wachthond($extdebug,1, "########################################################################");
	wachthond($extdebug,1, "### ACCOUNT CONFIG - START ONETIMELINK & CHECKSUM VOOR             $displayname");
	wachthond($extdebug,3, "########################################################################");

	$leeftijd_rond					= trim($result_contact[0]['WERVING.nextkamp_rondjaren'])	?? NULL;
	$leeftijd_deci					= trim($result_contact[0]['WERVING.nextkamp_decimalen'])	?? NULL;
	$privacy_onetimelink_date 		= $result_contact[0]['PRIVACY.onetimelink_date'] 			?? NULL;
	$privacy_checksum_date	  		= $result_contact[0]['PRIVACY.checksum_date'] 				?? NULL;
	$privacy_onetimelink_aanvraag	= $result_contact[0]['PRIVACY.onetimelink_aanvraag'] 		?? NULL;
	$privacy_is_test	  			= $result_contact[0]['PRIVACY.is_test'] 					?? NULL;

	wachthond($extdebug,2, 'displayname', 							$displayname);
	wachthond($extdebug,2, 'crm_drupalnaam', 						$crm_drupalnaam);
	wachthond($extdebug,2, 'crm_contactid', 						$crm_contactid);
	wachthond($extdebug,2, 'crm_drupalid', 							$crm_drupalid);
	wachthond($extdebug,2, 'privacy_onetimelink_date',				$privacy_onetimelink_date);
	wachthond($extdebug,2, 'privacy_checksum_date', 				$privacy_checksum_date);
	wachthond($extdebug,2, 'privacy_onetimelink_aanvraag', 			$privacy_onetimelink_aanvraag);
	wachthond($extdebug,2, 'privacy_is_test', 						$privacy_is_test);

	if (empty($crm_drupalid)) {

		wachthond($extdebug,3, "########################################################################");
		wachthond($extdebug,1, "### ACCOUNT CONFIG - 1.0 CHECK & GENERATE DRUPAL ACCOUNT",			     "[PREP]");
		wachthond($extdebug,3, "########################################################################");

        ###########################################################################################
        ### FORCE UPDATE CONTACT VIA TRIGGER JAAROVERZICHT (zit zet oa. drupal account check in gang)
        ###########################################################################################

		// M61: TODO: 	gevaar is dat door deze trigger er een endless loop ontstaat
		// 				maar als het goed is, is er hierna wel een drupal cms account

        $params_contact = [
            'checkPermissions'  => FALSE,
            'debug' => $apidebug,
            'where' => [
                ['id',       '=',  $crm_contactid],
            ],
            'values' => [
                'id'            => $crm_contactid,
            ],
        ];

      	$params_contact['values']['JAAROVERZICHT.trigger_jaaroverzicht']  = $today_datetime;
        wachthond($extdebug,3, "params_contact",                    $params_contact);
//      $result_contact = civicrm_api4('Contact', 'update',         $params_contact);
        wachthond($extdebug,9, "result_contact",                    $result_contact);

/*
        $today_nextkamp_lastnext = find_lastnext($today_datetime); 
        wachthond($extdebug,4, 'today_nextkamp_lastnext',           $today_nextkamp_lastnext);
        $today_nextkamp_start_date  =   $today_nextkamp_lastnext['next_start_date'];
        wachthond($extdebug,3, 'today_nextkamp_start_date',         $today_nextkamp_start_date);

        $leeftijd_nextkamp 		= leeftijd_civicrm_diff('nextkamp',	$birth_date, $today_nextkamp_start_date);
        wachthond($extdebug,4, 'leeftijd_nextkamp',       $leeftijd_nextkamp);
        $leeftijd_nextkamp_decimalen = $leeftijd_nextkamp['leeftijd_decimalen'] 		?? NULL;
        wachthond($extdebug,3, 'leeftijd_nextkamp_decimalen',   	$leeftijd_nextkamp_decimalen);

	    $array_contditjaar 		= base_cid2cont($crm_contactid);
	    wachthond($extdebug,4, 'array_contditjaar',         		$array_contditjaar);
    	$datum_belangstelling	= $array_contditjaar['datum_belangstelling'] 			?? NULL;
	    wachthond($extdebug,3, 'datum_belangstelling',   			$datum_belangstelling);
*/

/*
	    $array_email        	= email_civicrm_configure($array_contditjaar, NULL, NULL, $datum_belangstelling);
	    wachthond($extdebug,3, 'array_email',             			$array_email);

	    $user_mail          	= $array_email['user_mail']                     ?? NULL;
	    $email_home_email   	= $array_email['email_home_email']              ?? NULL;
	    $email_priv_email   	= $array_email['email_priv_email']              ?? NULL;

	    wachthond($extdebug,1, 'user_mail',                 		$user_mail);
*/
//	    drupal_civicrm_configure($crm_contactid, $displayname, $user_mail, $ditjaar_array, NULL);


	}

	wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,1, "### ACCOUNT CONFIG - 1.1 CHECK AND GENERATE ONETIME LOGIN",             "[PREP]");
    wachthond($extdebug,3, "########################################################################");

    find_fiscalyear();

    $today_fiscalyear_start     = Civi::cache()->get('cache_today_fiscalyear_start')    ?? NULL;
    $today_fiscalyear_einde     = Civi::cache()->get('cache_today_fiscalyear_einde')    ?? NULL;
    
    wachthond($extdebug,4, 'today_fiscalyear_start',    $today_fiscalyear_start);
    wachthond($extdebug,4, 'today_fiscalyear_einde',    $today_fiscalyear_einde);        

    // --- NIEUWE BEVEILIGING: VERSHEIDS CHECK ---
    // Als de huidige link minder dan 5 minuten (300 sec) oud is, doe dan NIETS.
    if (!empty($privacy_onetimelink_date)) {
        
        $link_leeftijd = time() - strtotime($privacy_onetimelink_date);
        
        if ($link_leeftijd < 300) { 
            // 1. LINK IS TE VERS -> BLOKKEER ALLES
            wachthond($extdebug, 2, "Onetimelink Skip", "Huidige link is pas $link_leeftijd sec oud. Geen actie nodig.");
            
            // Forceer triggers op 0 (STOP)
            $onetimelink_date_request = 0;
            $onetimelink_date_visited = 0;
            
        } else {
            // 2. LINK IS OUD -> DOE NORMALE CHECK
            // Pas als de link oud is, gaan we de datums vergelijken
            $onetimelink_date_request = date_bigger($today_fiscalyear_start, $privacy_onetimelink_date);
            $onetimelink_date_visited = date_bigger($privacy_onetimelink_date, $today_fiscalyear_einde);
        }
    } else {
        // 3. GEEN DATUM -> ALTIJD CHECKEN
        $onetimelink_date_request = date_bigger($today_fiscalyear_start, $privacy_onetimelink_date);
        $onetimelink_date_visited = date_bigger($privacy_onetimelink_date, $today_fiscalyear_einde);
    }
    // --- EINDE BEVEILIGING ---

    // LET OP: HIER HEB IK DE REGELS WEGGEHAALD DIE HET RESULTAAT OVERSCHREVEN!
    
    // Alleen nog even loggen wat de uitkomst is geworden
    wachthond($extdebug,2, "onetimelink_date_request",                      $onetimelink_date_request);
    wachthond($extdebug,2, "onetimelink_date_visited",                      $onetimelink_date_visited);

    if ($onetimelink_date_request == 1 OR $onetimelink_date_visited == 1) {
        $new_onetimelink_array  = account_generate_onetimelink($crm_drupalid,   $privacy_onetimelink_date);
        $new_onetimelink_url    = $new_onetimelink_array['onetimelink_url']     ?? NULL;
        $new_onetimelink_date   = $new_onetimelink_array['onetimelink_date']    ?? NULL;
        
        wachthond($extdebug,2, 'new_onetimelink_url',           $new_onetimelink_url);
        wachthond($extdebug,2, 'new_onetimelink_date',          $new_onetimelink_date);
    }
    
	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### ACCOUNT CONFIG - 1.2 CHECK AND GENERATE LOGIN CHECKSUM",	 "[PREP]");
	wachthond($extdebug,3, "########################################################################");

	$checksum_date_request	= date_bigger($today_fiscalyear_start,   	$privacy_checksum_date);
	$checksum_date_visited 	= date_bigger($privacy_checksum_date, 		$today_fiscalyear_einde);
	
	wachthond($extdebug,2, "checksum_date_request",						$checksum_date_request);
	wachthond($extdebug,2, "checksum_date_visited",						$checksum_date_visited);
	
	// OPLOSSING: Trigger ook als $privacy_checksum_date leeg is
	if (empty($privacy_checksum_date) || $checksum_date_request == 1 || $checksum_date_visited == 1) {
		
		$new_checksum_array 		= account_generate_checksum($crm_contactid,		$privacy_checksum_date);
		$new_checksum_code			= $new_checksum_array['checksum_code'] 			?? NULL;
		$new_checksum_date			= $new_checksum_array['checksum_date'] 	 		?? NULL;
		$new_onetimelink_request	= $new_checksum_array['onetimelink_request'] 	?? NULL;
		
		wachthond($extdebug,2, 'new_checksum_code',				$new_checksum_code);
		wachthond($extdebug,2, 'new_checksum_date',				$new_checksum_date);
		wachthond($extdebug,2, 'new_onetimelink_request',		$new_onetimelink_request);
		
		// Schrijf de gegenereerde checksum direct weg via eigen functie
		account_write_checksum($crm_contactid, $new_checksum_code, $new_checksum_date, $new_onetimelink_request);
	}

	wachthond($extdebug,3, "########################################################################");
	wachthond($extdebug,1, "### ACCOUNT CONFIG - 1.3 CHECK OP TESTDEELNEMER",			 	 "[TEST]");
	wachthond($extdebug,3, "########################################################################");

	$test_group_id = 1595; 	// GROEP TESTDEEL & TESTLEID

	$params_check_group = [
		'checkPermissions' => FALSE,
		'select' => ['id'],
		'where' => [
			['contact_id', '=', $crm_contactid],
			['group_id', '=', $test_group_id],
			['status', '=', 'Added'],
		],
	];

	$result_check_group = civicrm_api4('GroupContact', 'get', $params_check_group);

	// Bepaal status: forceer integer 1 of 0
	if ($result_check_group->count() > 0) {
		$new_istest = 1;
		$log_label = "WEL gevonden (1)";
	} else {
		$new_istest = 0;
		$log_label = "NOT gevonden (0)";
	}
	
	wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 1, "### ACCOUNT CONFIG - 2.0 WRITE DATA TO DB (APIv4 UPDATE)", 	   "[FIN]");
    wachthond($extdebug, 3, "########################################################################");

	$params_contact_update = [
		'checkPermissions' => FALSE,
		'where' 	=> [['id', '=', $crm_contactid]],
		'values' 	=> [],
	];

	// CHECK 1: Is_test (Alleen toevoegen als het verschilt van de huidige DB waarde)
	if ($new_istest != $privacy_is_test) {
		$params_contact_update['values']['PRIVACY.is_test'] = $new_istest;
		wachthond($extdebug, 2, 'change_detected', "Is_test wijzigt van [$privacy_is_test] naar [$new_istest]");
	}

	// CHECK 2: Onetimelinks & Checksums
	if (!empty($new_onetimelink_url))     { $params_contact_update['values']['PRIVACY.onetimelink_url']     = $new_onetimelink_url; }
	if (!empty($new_onetimelink_date))    { $params_contact_update['values']['PRIVACY.onetimelink_date']    = $new_onetimelink_date; }
	if (!empty($new_checksum_code))       { $params_contact_update['values']['PRIVACY.checksum']            = $new_checksum_code; }
	if (!empty($new_checksum_date))       { $params_contact_update['values']['PRIVACY.checksum_date']       = $new_checksum_date; }
	if (!empty($new_onetimelink_request)) { $params_contact_update['values']['PRIVACY.onetimelink_request'] = $new_onetimelink_request; }

	// VOER UIT indien er wijzigingen zijn
	if (!empty($params_contact_update['values'])) {
		try {
			$result_update = civicrm_api4('Contact', 'update', $params_contact_update);
			wachthond($extdebug, 1, "API4 Update Succes", "Contact $crm_contactid bijgewerkt. Velden: " . implode(', ', array_keys($params_contact_update['values'])));
		} catch (\Exception $e) {
			wachthond($extdebug, 1, "API4 Update ERROR", $e->getMessage());
		}
	} else {
		wachthond($extdebug, 2, "API4 Skip", "Geen wijzigingen gedetecteerd voor $crm_contactid. Update overgeslagen.");
	}

// 2.1 STUUR ONETIMELINK MAIL
    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 1, "### ACCOUNT CONFIG - 2.1 STUUR ONETIMELINK MAIL",             "[EMAIL]");
    wachthond($extdebug, 3, "########################################################################");

    $should_send_mail   = FALSE;
    
    // Check de aanvraagtijd (Logica om loop te voorkomen)
    if (!empty($privacy_onetimelink_aanvraag)) {
        $aanvraag_timestamp = strtotime($privacy_onetimelink_aanvraag);
        $verschil_seconden  = time() - $aanvraag_timestamp;
        
        // Debug regel: Zodat je ziet hoeveel seconden er tussen zit
        wachthond($extdebug, 2, "Mail Time Check", "Verschil is: {$verschil_seconden}s (Aanvraag: $privacy_onetimelink_aanvraag)");

        // Check: Is de aanvraag recent? (Marge van -5 tot 90 seconden)
        if ($verschil_seconden >= -5 && $verschil_seconden <= 90) {
            $should_send_mail = TRUE;
        } else {
            wachthond($extdebug, 2, "Mail Skip", "Aanvraag is te oud ($verschil_seconden sec).");
        }
    } else {
        wachthond($extdebug, 2, "Mail Skip", "Geen 'onetimelink_aanvraag' datum gevonden.");
    }    

// DE DEFINITIEVE FIX:
    // We checken alleen of er een URL is ($new_onetimelink_url) en of de timing klopt ($should_send_mail).
    // De check ($onetimelink_date_request == 1) halen we weg, want die is te strikt.
    
    if (!empty($new_onetimelink_url) && $should_send_mail === TRUE) {

        wachthond($extdebug, 3, "########################################################################");
        wachthond($extdebug, 1, "### ACCOUNT CONFIG - 3.0 ONETIME LOGIN LINK",                   "[SEND]");
        wachthond($extdebug, 3, "########################################################################");

        // Bepaal template ID (standaard 621, tenzij visited trigger)
        $msgid = 621; 
        if (isset($onetimelink_date_visited) && $onetimelink_date_visited == 1) { 
             // Optioneel: als je een aparte mail hebt voor 'link was al gebruikt', zet die hier
             // $msgid = 596; 
        }

        // Verstuur de mail
        $email_onetimelink = account_send_onetimelink($crm_contactid, $msgid);

    } else {
        // Debugging: Waarom GEEN mail?
        if ($should_send_mail === TRUE && empty($new_onetimelink_url)) {
            wachthond($extdebug, 1, "### INFO: Geen mail gestuurd: Wel tijd, maar geen nieuwe URL gegenereerd.");
        }
    }

	wachthond($extdebug,2, "########################################################################");
	wachthond($extdebug,2, "### ACCOUNT CONFIG - CONFIGURE ACCOUNT FOR $displayname", 	    "[EINDE]");
	wachthond($extdebug,3, "########################################################################");

	// Belangrijk: Geef het ID weer vrij zodat een volgend (uniek) request 
    // in hetzelfde PHP-proces wel weer uitgevoerd kan worden.
    if (!empty($contactid)) {
//      unset($processing_account[$contactid]);
    }

}

/**
 * Genereert een one-time login link voor Drupal.
 */
function account_generate_onetimelink($cmsid, $checkdate = NULL)
{
    $extdebug = 3; // 1=basic, 2=verbose, 3=params, 4=results

    if (empty($cmsid)) {
        wachthond($extdebug, 2, 'cmsid', "[EMPTY]");
        return NULL;
    }

    // --- 1. CONFIGURATIE OPHALEN ---
    $config             = find_fiscalyear();
    
    // VARIABELEN TOEWIJZEN (VOLUIT)
    $onetimelink_ttl    = $config['daysuntil_fyeinde'] ?? 30;
    $onetimelink_date   = $config['today_date'];
    $privacy_drupalid   = $cmsid;

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 1, "### ACCOUNT OTL GEN - GENERATE LINK (TTL: $onetimelink_ttl days)", "[GENERATE]");
    wachthond($extdebug, 3, "########################################################################");

    // --- 2. LINK GENEREREN ---
    $account = user_load($privacy_drupalid);
    
    if (!$account) {
        wachthond($extdebug, 2, "Fout", "Drupal user $privacy_drupalid niet gevonden.");
        return NULL;
    }

    $onetimelink_url = one_time_login_short_link(
        $account,
        '+'. $onetimelink_ttl . ' day', 
        'https://www.onvergetelijk.nl/account'
    );

    // LOGGING
	wachthond($extdebug, 1, "onetimelink_url", $onetimelink_url);
    wachthond($extdebug, 1, "onetimelink_ttl", $onetimelink_ttl);

    // --- 3. RETURN ARRAY BOUWEN ---
    $onetimelink_array = array(
        'onetimelink_url'   => $onetimelink_url,
        'onetimelink_date'  => $onetimelink_date,
    );

    // Debug resultaat
    wachthond($extdebug, 4, "onetimelink_array", $onetimelink_array);

    return $onetimelink_array;
}

/**
 * Genereert een checksum voor CiviCRM.
 */
function account_generate_checksum($crmid, $checkdate = NULL)
{
    $extdebug = 3; // 1=basic, 2=verbose, 3=params, 4=results

    if (empty($crmid)) {
        wachthond($extdebug, 2, 'crmid', "[EMPTY]");
        return NULL;
    }

    // --- 1. CONFIGURATIE OPHALEN ---
    $config             = find_fiscalyear();
    
    // VARIABELEN TOEWIJZEN (VOLUIT)
    $checksum_ttl       = $config['daysuntil_fyeinde'] ?? 30;
    $checksum_date      = $config['today_date'];
    $checksum_geldig    = $config['today_einde']; // Einde boekjaar
    $privacy_contactid  = $crmid;

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 1, "### ACCOUNT CHK GEN - GENERATE CHECKSUM (TTL: $checksum_ttl days)", "[GENERATE]");
    wachthond($extdebug, 3, "########################################################################");

    // --- 2. CHECKSUM OPHALEN (APIv4) ---
    $create_checksum = civicrm_api4('Contact', 'getChecksum', [
        'contactId'        => $privacy_contactid,
        'ttl'              => $checksum_ttl,
        'checkPermissions' => FALSE,
    ]);

    $checksum_code       = $create_checksum[0]['checksum'];
    $onetimelink_request = "https://www.onvergetelijk.nl/form/inloglink?cid1=$privacy_contactid&cs=$checksum_code";

    // LOGGING
	wachthond($extdebug, 1, "contact_checksum", $checksum_code);
    wachthond($extdebug, 1, "checksum_ttl",     $checksum_ttl);

    // --- 3. RETURN ARRAY BOUWEN ---
    $checksum_array = array(
        'checksum_code'         => $checksum_code,
        'checksum_date'         => $checksum_date,
        'checksum_geldig'       => $checksum_geldig,
        'onetimelink_request'   => $onetimelink_request,
    );

    // Debug resultaat
    wachthond($extdebug, 4, "checksum_array", $checksum_array);

    return $checksum_array;
}

/**
 * Schrijft de gegenereerde link weg naar het CiviCRM contact.
 */
function account_write_onetimelink($crmid, $url, $date)
{
    $extdebug = 3;
    $apidebug = FALSE;

    if (empty($crmid) || empty($url) || empty($date)) {
        wachthond($extdebug, 2, "account_write_onetimelink", "[EMPTY DATA] ID:$crmid");
        return;
    }

    // VARIABELEN OVERZETTEN NAAR LEESBARE NAMEN
    $privacy_contactid  = $crmid;
    $onetimelink_url    = $url;
    $onetimelink_date   = $date;

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 1, "### ACCOUNT OTL WRITE - SAVE TO CONTACT", "[WRITE]");
    wachthond($extdebug, 3, "########################################################################");

    $params_update_onetimelink = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'where'            => [['id', '=', $privacy_contactid]],
        'values'           => [
            'PRIVACY.onetimelink_url'  => $onetimelink_url,
            'PRIVACY.onetimelink_date' => $onetimelink_date,
        ],
    ];

    wachthond($extdebug, 3, 'params_update_onetimelink', $params_update_onetimelink);

    $result = civicrm_api4('Contact', 'update', $params_update_onetimelink);
    
    wachthond($extdebug, 4, 'result_update_onetimelink', $result);

    return 1;
}

/**
 * Schrijft de gegenereerde checksum weg naar het CiviCRM contact.
 */
function account_write_checksum($crmid, $code, $date, $link)
{
    $extdebug = 3;
    $apidebug = FALSE;

    if (empty($crmid) || empty($code) || empty($date) || empty($link)) {
        wachthond($extdebug, 2, "account_write_checksum", "[EMPTY DATA] ID:$crmid");
        return;
    }

    // VARIABELEN OVERZETTEN NAAR LEESBARE NAMEN
    $privacy_contactid   = $crmid;
    $checksum_code       = $code;
    $checksum_date       = $date;
    $onetimelink_request = $link;

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 1, "### ACCOUNT CHK WRITE - SAVE TO CONTACT", "[WRITE]");
    wachthond($extdebug, 3, "########################################################################");

    $params_update_checksum = [
        'checkPermissions' => FALSE,
        'debug'            => $apidebug,
        'where'            => [['id', '=', $privacy_contactid]],
        'values'           => [
            'PRIVACY.checksum'            => $checksum_code,
            'PRIVACY.checksum_date'       => $checksum_date,
            'PRIVACY.onetimelink_request' => $onetimelink_request,
        ],
    ];

    wachthond($extdebug, 3, 'params_update_checksum', $params_update_checksum);

    $result = civicrm_api4('Contact', 'update', $params_update_checksum);

    wachthond($extdebug, 4, 'result_update_checksum', $result);
}

/**
 * Verstuurt de e-mail met de link.
 */
function account_send_onetimelink($crmid, $msgid = NULL)
{
    $extdebug = 3; 
    
    if ($msgid == NULL) { $msgid = 621; }

    $privacy_contactid = $crmid;

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 1, "### ACCOUNT OTL SEND - SEND EMAIL", "[CID:$privacy_contactid]");
    wachthond($extdebug, 3, "########################################################################");

    try {
        civicrm_api3('Email', 'send', [
            'contact_id'  => $privacy_contactid,
            'template_id' => $msgid,
        ]);
    } catch (Exception $e) {
        wachthond($extdebug, 1, "ERROR SENDING MAIL", $e->getMessage());
    }
}