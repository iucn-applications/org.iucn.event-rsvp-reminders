<?php

require_once 'event_rsvp_reminders.civix.php';
require_once 'CRM/EventRsvpReminders/RSVPActionMapping.php';
// phpcs:disable
use CRM_EventRsvpReminders_ExtensionUtil as E;
// phpcs:enable

/**
 * Add token listener and ActionMappings for event reminders
 * 
 * Implements hook_civicrm_container
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container/
 * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
 */
function event_rsvp_reminders_civicrm_container(\Symfony\Component\DependencyInjection\ContainerBuilder $container)
{
  $container->addResource(new \Symfony\Component\Config\Resource\FileResource(__FILE__));
  // Get the dispatcher
  $dispatcher = $container->findDefinition('dispatcher');
  // Add listener for tokens
  $dispatcher->addMethodCall('addListener',
    array('civi.token.eval', '_event_rsvp_reminders_evaluate_tokens')
  )->setPublic(TRUE);
  // Add listener for ActionMappings
  $dispatcher->addMethodCall('addListener', 
    array('civi.actionSchedule.getMappings', ['CRM_Event_RSVPActionMapping', 'onRegisterActionMappings'])
  );
}

/**
 * Dynamically define tokens based on fields from Custom Group 'Event Invitation'
 * 
 * Implements hook_civicrm_tokens().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_tokens/
 */
function event_rsvp_reminders_civicrm_tokens(&$tokens) {
  // Make sure we are scheduling a reminder - no point in adding these tokens on other contexts
  if( strpos(CRM_Utils_System::currentPath(), "scheduleReminders") !== FALSE ){
    $tokens = array_merge($tokens, _event_rsvp_reminders_civicrm_token_fields(TRUE) );
  }
}

/**
 * Evaluate tokens
 *
 * Listener function to evaluate the tokens
 *
 * @param \Civi\Token\Event\TokenValueEvent $e Token event
 * @throws error if it cannot evaluate the event
 **/
function _event_rsvp_reminders_evaluate_tokens(\Civi\Token\Event\TokenValueEvent $e)
{
  $token_processor = $e->getTokenProcessor();
  // Validate we're in Schedule reminder, else return
  if( $token_processor->context['controller'] !== "CRM_Core_BAO_ActionSchedule" ) return;

  $rowContexts = $e->getTokenProcessor()->rowContexts;
  foreach ($e->getRows() as $row) {
    $row->format('text/html');
    // Get values
    if( isset($rowContexts[$row->tokenRow]['actionSearchResult']) ) {
      // Context
      $context = $rowContexts[$row->tokenRow]['actionSearchResult'];
      //Contact id
      $cid = $context->contactId ?? $context->contact_id;

      //Participant id
      if( isset( $context->entityTable ) && $context->entityTable == 'civicrm_participant' ){
        $pid = $context->id ?? $context->entity_id;
      }
      if( !$cid || !$pid ) continue;

      // Calculate url validity based on event dates:
      if( isset($context->event_id) ){
        $event = \Civi\Api4\Event::get()
          ->addSelect('start_date', 'registration_end_date')
          ->addWhere('id', '=', $context->event_id)
          ->execute();
        if( $event && $event->rowCount === 1 ) $url_date_to = ( $event[0]['registration_end_date'] && $event[0]['registration_end_date'] < $event[0]['start_date'] ) ? new dateTime($event[0]['registration_end_date'])  : new DateTime($event[0]['start_date']);
      }
      // set a default of 6 months
      if( !$url_date_to ) $url_date_to = new DateTime("+6 months");
      // Checksum lifetime in hours
      $checksum_lifetime = $url_date_to->diff(new DateTime())->days * 24;
      // Generate checksum
      $checksum = CRM_Contact_BAO_Contact_Utils::generateChecksum($cid, time(), $checksum_lifetime );

      // Get token definitions
      $token_fields = _event_rsvp_reminders_civicrm_token_fields();
      // Evaluate tokens
      foreach( $token_fields as $action => $field){
        foreach( $field['values'] as $value ){
          $response = $value['value'];
          $url = \CRM_Utils_System::url("civicrm/eventrsvp","a=$action&r=$response&pid=$pid&cid=$cid&cs=$checksum",true,null,false);
          // Token values
          $row->tokens('event', "{$action}_{$response}_url",$url);
          $row->tokens('event', "{$action}_{$response}", sprintf("<a href='%s' target='_blank' class='btn'>{$value['label']}</a>", $url ) );
        }
      }
    }
  }
}

/**
 * Get token definitions
 *
 * Generate the token definitions from the Custom group 'Event Invitation'
 * Used to define and process the tokens
 * 
 * @param bool $return_tokens
 *    return token definitions if true, fields data if false
 **/
function _event_rsvp_reminders_civicrm_token_fields($return_tokens = FALSE)
{
  //Hardcoded list of value that will NOT create tokens
  $no_token_values = '/(pending)|(no[-\s_\.]*answer)/i';

  $token_fields = array();
  $tokens = array();
  // Get fields from "Invitation RSVP" group that have multiple options
  $custom_fields = \Civi\Api4\CustomGroup::get()
    ->addSelect('id', 'custom_field.name', 'custom_field.label', 'custom_field.option_group_id')
    ->setJoin([
      ['CustomField AS custom_field', TRUE, NULL, ['custom_field.custom_group_id', '=', 'id']],
    ])
    ->addWhere('name', '=', 'Event_Invitation')
    ->addWhere('custom_field.option_group_id', 'IS NOT NULL')
    ->addChain('values', \Civi\Api4\OptionValue::get()
      ->addSelect('value', 'label')
      ->addWhere('option_group_id', '=', '$custom_field.option_group_id')
    )
    ->execute();
  // Process results
  foreach($custom_fields as $field){
    $fname = $field['custom_field.name'];
    if( !$return_tokens ){
      $token_fields[ $fname ] = array(
        'name' => $fname,
        'label' => $field['custom_field.label'],
      );
    }
    foreach( $field['values'] as $value ){
      if( preg_match($no_token_values, $value['value']) ) continue;
      if( $return_tokens ){
        $tokens['event']["event.{$fname}_{$value['value']}"]      = E::ts($value['label']) . ' :: ' . E::ts("Event Invitation: {$field['custom_field.label']}");
        $tokens['event']["event.{$fname}_{$value['value']}_url"]  = E::ts("{$value['label']} - url") . ' :: ' . E::ts("Event Invitation: {$field['custom_field.label']}");
      } else $token_fields[$fname]['values'][] = $value;
    }
  }
  return $return_tokens ? $tokens : $token_fields;
}

/**
 * Implements hook_civicrm_buildForm
 *
 * Alter the Schedule reminder form
 *
 * @param string $formName the name of the form
 * @param CRM_Core_Form $form the form array to be manipulated
 **/
function event_rsvp_reminders_civicrm_buildForm($formName, &$form)
{
  // $form->get
  // echo $formName;
  // Civi::resources()->addScript("console.log('$formName');");
  // $('#entity_0, #limit_to', $form).change(buildSelects);
  if( $formName == "CRM_Admin_Form_ScheduleReminders" ){
    // echo $form->getContext();

    
    // Civi::resources()->addScript("$('#entity_1, #limit_to', $('form.CRM_Admin_Form_ScheduleReminders')).change(buildSelects);");
     

    // Change recipient listing label
    // $form['_elements'][ $form['_elementIndex']['recipient_listing'] ]['_label'] = E::ts('Values');
    // $form['_elements'][ $form['_elementIndex']['recipient_listing'] ]['_label'] = E::ts('Values');
    $recipient_listing = $form->getElement("recipient_listing");
    $recipient_listing->setLabel(E::ts('Values'));
    $recipient_listing->setAttribute('placeholder', '- selecet Value(s) -');
    // $
    // $recipient_listing = $form->updateElementAttr("recipient_listing", array(  ) );
    // $recipient_listing->set

  }
  // if( $formName === "CRM_Event_Form_Participant" || $formName === "CRM_Event_Form_ParticipantView" ){
  //   // echo "li";
  // }
}



/**
 * Removes Data from the DB on uninstall
 *
 * Backs up current configuration into XML file
 * Backs data up into sql insert statement
 * Deletes everyting from the DB
 *
 **/
function _event_rsvp_reminders_on_uninstall()
{
  $restore_xml = "<CustomData>";

  try{
    // Get Event invitation Custom Field Group
    $event_invitation_group = \Civi\Api4\CustomGroup::get(FALSE)
      ->addWhere("name","=","Event_Invitation")
      ->execute();
      // @TODO -  decide what todo on error
    if( !$event_invitation_group || $event_invitation_group->rowCount !== 1 ) throw new Exception("Could not retrieve Custom Field Group 'Event Invitation'.");
    $event_invitation_group = $event_invitation_group[0];
    //Write CustomGroup restore xml
    $restore_xml .= "<CustomGroups>
      <CustomGroup>";
    foreach( $event_invitation_group as $key => $value){
      if( $key == 'id' ) continue;
      if( !is_null($value) ){
        if( $value === FALSE ) $value = 0;
        $restore_xml .= "<$key>$value</$key>";
      }
    }
    $restore_xml .= "</CustomGroup>
      </CustomGroups>";

    // Get Fields for Event Invitation
    $event_invitation_group_fields = \Civi\Api4\CustomField::get(FALSE)
      ->addSelect('*', 'option_group.name')
      ->addWhere('custom_group_id', '=', $event_invitation_group['id'])
      ->addJoin('OptionGroup AS option_group',FALSE,null, ['option_group.id','=','option_group_id'] )
      ->execute();
    // Of these fields, get the Selects (group options)
    $option_group_ids = array();
    $option_group_ids_to_delete = array();
    if( $event_invitation_group_fields && $event_invitation_group_fields->rowCount ){
      $restore_xml .= "<CustomFields>";
      foreach( $event_invitation_group_fields as $field ){
        // Store option group ids
        if( $field['option_group_id'] ) $option_group_ids[ $field['option_group_id'] ] = $field['option_group_id'];
        // Write CustomFields to restore xml
        $restore_xml .= "<CustomField>";
        foreach( $field as $key => $value){
          if( $key == 'id' || $key == 'option_group_id' || $key == 'custom_group_id' || $key == 'option_group.name' ) continue;
          if( !is_null($value) ){
            if( $value === FALSE ) $value = 0;
            $restore_xml .= "<$key>$value</$key>";
          }
        }
        if($field['option_group.name']) $restore_xml .= "<option_group_name>{$field['option_group.name']}</option_group_name>";
        $restore_xml .= "<custom_group_name>{$event_invitation_group['name']}</custom_group_name>
          </CustomField>";
      }
      $restore_xml .= "</CustomFields>";
    }
    // Get Option groups
    if( !empty($option_group_ids) ){
      // Make sure that the option groups are not being used anywhere else
      $other_fields_using_option_groups = \Civi\Api4\CustomField::get(FALSE)
        ->addSelect('option_group_id')
        ->addWhere('custom_group_id','!=', $event_invitation_group['id'] )
        ->addWhere('option_group_id','IN', $option_group_ids )
        ->execute()
        ->indexBy('option_group_id');
      // If failure, do not proceed: we don't want to delet by mistake
      if( $other_fields_using_option_groups ){
        // Get Option Groups
        $option_groups = \Civi\Api4\OptionGroup::get(FALSE)
          ->addWhere('id', 'IN', $option_group_ids)
          ->addWhere('id', 'NOT IN', $other_fields_using_option_groups)
          ->execute();
        if( $option_groups && $option_groups->rowCount ) {
          // Write Option Groups to restore xml
          $restore_xml .= "<OptionGroups>";
          foreach( $option_groups as $option_group ){
            // If other fields are using this option group, ignore
            $option_group_ids_to_delete[] = $option_group['id'];
            //Write CustomGroup restore xml
            $restore_xml .= "<OptionGroup>";
            foreach( $option_group as $key => $value){
              if( $key == 'id' ) continue;
              if( !is_null($value) ){
                if( $value === FALSE ) $value = 0;
                $restore_xml .= "<$key>$value</$key>";
              }
            }
            $restore_xml .= "</OptionGroup>";            
          }
          $restore_xml .= "</OptionGroups>";
        }
        // Get Option Values
        $option_values = \Civi\Api4\OptionValue::get(FALSE)          
          ->addSelect('*', 'option_group.name')
          ->addWhere('option_group_id', 'IN', $option_group_ids)
          ->addJoin('OptionGroup AS option_group',FALSE,null, ['option_group.id','=','option_group_id'] )
          ->execute();
        
        if( $option_values && $option_values->rowCount ) {

          // Write Option Groups to restore xml
          $restore_xml .= "<OptionValues>";
          foreach( $option_values as $option_value ){
            //Write CustomGroup restore xml
            $restore_xml .= "<OptionValue>";
            foreach( $option_value as $key => $value){
              if( $key == 'id' || $key == 'option_group_id' || $key == 'option_group.name' ) continue;
              if( !is_null($value) ){
                if( $value === FALSE ) $value = 0;
                $restore_xml .= "<$key>$value</$key>";
              }
            }
            $restore_xml .= "<option_group_name>{$option_value['option_group.name']}</option_group_name>
              </OptionValue>";            
          }
          $restore_xml .= "</OptionValues>";
        }

      } 
    }

    $restore_xml .= "</CustomData>";
    $simple_xml = simplexml_load_string($restore_xml);
    // Write restore xml
    // if( !file_put_contents(__DIR__.DIRECTORY_SEPARATOR."xml".DIRECTORY_SEPARATOR."restore.xml", $restore_xml) ) throw new Exception("Could not write the restore file.");
    if( !$simple_xml || !($simple_xml->asXML(__DIR__.DIRECTORY_SEPARATOR."xml".DIRECTORY_SEPARATOR."restore.xml")) ) throw new Exception("Could not write the restore file.");

    // Get existing values for custom fields
    try{
      $custom_values = CRM_Core_DAO::executeQuery("SELECT * FROM {$event_invitation_group['table_name']}")->fetchAll();
    } catch (Throwable $th){
      // If already exists do nothing, else throw
      if( $th->getMessage() !== "DB Error: no such table") throw $th;
    }
    if( $custom_values && !empty($custom_values) ){
      // Prepare insert query
      $insert_query = "INSERT INTO ".$event_invitation_group['table_name'];
      // Add columns (not id)
      $tmp = $custom_values[0];
      unset($tmp['id']);
      $keys = array_keys($tmp);
      $insert_query .= " (". implode(", ", $keys) . ") VALUES ";
      foreach( $custom_values as $custom_value ){
        unset($custom_value['id']);
        $insert_query .= " ('". implode("', '", $custom_value) . "'),";
      }
      $insert_query = substr($insert_query,0,-1). ";";
    }
    // Write INSERT SQL to bkp file
    if($insert_query) {
      if( !file_put_contents(__DIR__.DIRECTORY_SEPARATOR."sql".DIRECTORY_SEPARATOR."event_invitation_bkp.sql", $insert_query) ) throw new Exception("Could not write the restore file.");
    }

    // Delete everything from the DB
    // Delete values
    try{      CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS {$event_invitation_group['table_name']}");
    } catch (Throwable $th){
      // If already exists do nothing, else throw
      if( $th->getMessage() !== "DB Error: no such table") throw $th;
    }
    if( !empty($option_group_ids_to_delete) ){
      // Delete Option Values
      \Civi\Api4\OptionValue::delete(FALSE)
      ->addWhere('option_group_id', 'IN', $option_group_ids_to_delete)
      ->execute();
      // Delete Option Group
      \Civi\Api4\OptionGroup::delete(FALSE)
        ->addWhere('id', 'IN', $option_group_ids_to_delete)
        ->execute();  
    }
    // Delete Custom Fields
    \Civi\Api4\CustomField::delete(FALSE)
      ->addWhere('custom_group_id', '=', $event_invitation_group['id'])
      ->execute();
    // Delete Custom group
    \Civi\Api4\CustomGroup::delete(FALSE)
      ->addWhere('id','=',$event_invitation_group['id'])
      ->execute();


  } catch(Throwable $th){
    // Log error and alert the user to manually remove the fields
    $error_title = E::ts("There was an error deleting the data for extension %1.", array( 1=>E::LONG_NAME));
    $error_text = E::ts("Error details:<br>
                          %1<br>
                          The extension has been uninstalled, but some data remains in the database.<br>
                          Please check Custom Field Group <b>%2</b>, all its Custom Fields and respective Option Goups and Option Values and manually delete the unecessary ones.<br>
                          You may also want to delete table <i>%3</i> from the database.",
                          array(
                            1=> $th->getMessage(),
                            2=> isset( $event_invitation_group['title'] ) ? $event_invitation_group['title'] : "Event Invitation",
                            3=> isset( $event_invitation_group['table_name'] ) ? $event_invitation_group['table_name'] : "civicrm_value_event_invitation",
                          ));  

    CRM_Core_Session::setStatus($error_text, $error_title, "error");

    // throw $th;
    // die();
  }

}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function event_rsvp_reminders_civicrm_config(&$config) {
  _event_rsvp_reminders_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function event_rsvp_reminders_civicrm_xmlMenu(&$files) {
  _event_rsvp_reminders_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function event_rsvp_reminders_civicrm_install() {
  _event_rsvp_reminders_civix_civicrm_install();
  
  // Check if we have a backup
  $recover_file = __DIR__.DIRECTORY_SEPARATOR."xml".DIRECTORY_SEPARATOR."restore.xml";
  $load_bkp = false;
  if( file_exists($recover_file) && 
      trim(file_get_contents($recover_file)) != false){
    $xml_file = $recover_file;
    $load_bkp = true;
  } else { 
    $xml_file = __DIR__.DIRECTORY_SEPARATOR."xml".DIRECTORY_SEPARATOR."auto_install.xml";
  }
  // Create/Recreate custom fields
  if( !file_exists( $xml_file ) ) throw new \Exception("Cannot install: cannot find field configuration.");
  $import = new CRM_Utils_Migrate_Import();
  try{
    $import->run($xml_file);
  } catch (Throwable $th){
    // If already exists do nothing, else throw
    if( $th->getMessage() !== "DB Error: already exists") throw $th;
  }
  // If restore bkp, load data
  if( $load_bkp ) {
    $data_bkp_query = file_get_contents(__DIR__.DIRECTORY_SEPARATOR."sql".DIRECTORY_SEPARATOR."event_invitation_bkp.sql");
    if($data_bkp_query) {
      CRM_Core_DAO::executeQuery($data_bkp_query);
    }
  }
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function event_rsvp_reminders_civicrm_postInstall() {
  _event_rsvp_reminders_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function event_rsvp_reminders_civicrm_uninstall() {
  _event_rsvp_reminders_civix_civicrm_uninstall();

  _event_rsvp_reminders_on_uninstall();
  
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function event_rsvp_reminders_civicrm_enable() {
  _event_rsvp_reminders_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function event_rsvp_reminders_civicrm_disable() {
  _event_rsvp_reminders_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function event_rsvp_reminders_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _event_rsvp_reminders_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function event_rsvp_reminders_civicrm_managed(&$entities) {
  _event_rsvp_reminders_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function event_rsvp_reminders_civicrm_caseTypes(&$caseTypes) {
  _event_rsvp_reminders_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function event_rsvp_reminders_civicrm_angularModules(&$angularModules) {
  _event_rsvp_reminders_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function event_rsvp_reminders_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _event_rsvp_reminders_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function event_rsvp_reminders_civicrm_entityTypes(&$entityTypes) {
  _event_rsvp_reminders_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_themes().
 */
function event_rsvp_reminders_civicrm_themes(&$themes) {
  _event_rsvp_reminders_civix_civicrm_themes($themes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function event_rsvp_reminders_civicrm_preProcess($formName, &$form) {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
//function event_rsvp_reminders_civicrm_navigationMenu(&$menu) {
//  _event_rsvp_reminders_civix_insert_navigation_menu($menu, 'Mailings', array(
//    'label' => E::ts('New subliminal message'),
//    'name' => 'mailing_subliminal_message',
//    'url' => 'civicrm/mailing/subliminal',
//    'permission' => 'access CiviMail',
//    'operator' => 'OR',
//    'separator' => 0,
//  ));
//  _event_rsvp_reminders_civix_navigationMenu($menu);
//}
