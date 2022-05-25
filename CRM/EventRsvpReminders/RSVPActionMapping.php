<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use Civi\ActionSchedule\RecipientBuilder;
// require_once DRUPAL_ROOT . "/profiles/civigo_master/modules/contrib/civicrm/CRM/Event/ActionMapping.php";

/**
 * Class CRM_Event_RSVPActionMapping
 *
 * This defines the scheduled-reminder functionality for CiviEvent
 * participants. It allows one to target messages on the
 * event's start-date/end-date, with additional filtering by
 * event-type, event-template, or event-id.
 */
class CRM_Event_RSVPActionMapping extends \CRM_Event_ActionMapping {

  protected $custom_fields_with_options = array();

  /**
   * Register our custom action mappings.
   * Overrides CiviCRM's default Event action Mappings
   *
   * @param \Civi\ActionSchedule\Event\MappingRegisterEvent $registrations
   */
  public static function onRegisterActionMappings(\Civi\ActionSchedule\Event\MappingRegisterEvent $registrations) {
    $registrations->register(CRM_Event_RSVPActionMapping::create([
      'id' => CRM_Event_ActionMapping::EVENT_TYPE_MAPPING_ID, // Override core ActionMapping
      'entity' => 'civicrm_participant',
      'entity_label' => ts('Event Type'),
      'entity_value' => 'event_type',
      'entity_value_label' => ts('Event Type'),
      'entity_status' => 'civicrm_participant_status_type',
      'entity_status_label' => ts('Participant Status'),
    ]));
    $registrations->register(CRM_Event_RSVPActionMapping::create([
      'id' => CRM_Event_ActionMapping::EVENT_NAME_MAPPING_ID, // Override core ActionMapping
      'entity' => 'civicrm_participant',
      'entity_label' => ts('Event Name'),
      'entity_value' => 'civicrm_event',
      'entity_value_label' => ts('Event Name'),
      'entity_status' => 'civicrm_participant_status_type',
      'entity_status_label' => ts('Participant Status'),
    ]));
    $registrations->register(CRM_Event_RSVPActionMapping::create([
      'id' => CRM_Event_ActionMapping::EVENT_TPL_MAPPING_ID, // Override core ActionMapping
      'entity' => 'civicrm_participant',
      'entity_label' => ts('Event Template'),
      'entity_value' => 'event_template',
      'entity_value_label' => ts('Event Template'),
      'entity_status' => 'civicrm_participant_status_type',
      'entity_status_label' => ts('Participant Status'),
    ]));
  }

  /**
   * Get a list of recipient types.
   *
   * Note: A single schedule may filter on *zero* or *one* recipient types.
   * When an admin chooses a value, it's stored in $schedule->recipient.
   *
   * @return array
   *   array(string $value => string $label).
   *   Ex: array('assignee' => 'Activity Assignee').
   */
  public function getRecipientTypes() {
    $parent_receipient_types = parent::getRecipientTypes();

    $event_id = null;
    // If we are Scheduling reminders from within the Event Config - which is the intended behavior - we can get the event id
    // And retrieve which Custom Fields are being used for this event and display only those
    // Otherwise, just show the full list of Custom Fields to Participants
    if( isset($_GET['context']) &&
      $_GET['context'] === 'event' &&
      isset($_GET['compId']) ){
      $event_id = $_GET['compId'];
    }

    $custom_recepients = array();
    $custom_fields_with_options = _get_fields_with_options($event_id);
    foreach($custom_fields_with_options as $field ){
      $custom_recepients[ $field['option_group.name'] ] = $field['label'];
    }

    return $parent_receipient_types + $custom_recepients;
  }

  /**
   * Get a list of recipients which match the given type.
   *
   * Note: A single schedule may filter on *multiple* recipients.
   * When an admin chooses value(s), it's stored in $schedule->recipient_listing.
   *
   * @param string $recipientType
   *   Ex: 'participant_role'.
   * @return array
   *   Array(mixed $name => string $label).
   *   Ex: array(1 => 'Attendee', 2 => 'Volunteer').
   * @see getRecipientTypes
   */
  public function getRecipientListing($recipientType) {
    switch ($recipientType) {
      case 'participant_role':
        return \CRM_Event_PseudoConstant::participantRole();

      case 'manual':
      case 'group':
        return [];

      default:

        if($recipientType) {
          $values = array_merge( array('null' => '- Empty -'), CRM_Core_OptionGroup::values($recipientType) );
          if( $values ) return $values;
        } 
        return [];
    }
  }

  /**
   * Generate a query to locate recipients who match the given
   * schedule.
   *
   * @param \CRM_Core_DAO_ActionSchedule $schedule
   *   The schedule as configured by the administrator.
   * @param string $phase
   *   See, e.g., RecipientBuilder::PHASE_RELATION_FIRST.
   * @param array $defaultParams
   *
   * @return \CRM_Utils_SQL_Select
   * @see RecipientBuilder
   */
  public function createQuery($schedule, $phase, $defaultParams) {
    $selectedValues = (array) \CRM_Utils_Array::explodePadded($schedule->entity_value);
    $selectedStatuses = (array) \CRM_Utils_Array::explodePadded($schedule->entity_status);

    $query = \CRM_Utils_SQL_Select::from("{$this->entity} e")->param($defaultParams);
    $query['casAddlCheckFrom'] = 'civicrm_event r';
    $query['casContactIdField'] = 'e.contact_id';
    $query['casEntityIdField'] = 'e.id';
    $query['casContactTableAlias'] = NULL;
    $query['casDateField'] = str_replace('event_', 'r.', $schedule->start_action_date);
    if (empty($query['casDateField']) && $schedule->absolute_date) {
      $query['casDateField'] = "'" . CRM_Utils_Type::escape($schedule->absolute_date, 'String') . "'";
    }

    $query->join('r', 'INNER JOIN civicrm_event r ON e.event_id = r.id');
    if ($schedule->recipient_listing && $schedule->limit_to) {
      switch ($schedule->recipient) {
        case 'participant_role':
          $query->where("e.role_id IN (#recipList)")
            ->param('recipList', \CRM_Utils_Array::explodePadded($schedule->recipient_listing));
          break;
        // Is this necessary? does 'manual' and 'group' end up here?
        case 'manual':
        case 'group':
          break;
  
        default:

          $event_id = ( $schedule->entity_value && (int) $schedule->entity_value == $schedule->entity_value ) ? $schedule->entity_value : null; 
          $custom_fields_with_options = _get_fields_with_options($event_id);

          // Filter by custom fields
          if( isset( $custom_fields_with_options[$schedule->recipient] ) ){
            // Field definition
            $field = $custom_fields_with_options[$schedule->recipient];
            // Values
            $recipList = \CRM_Utils_Array::explodePadded($schedule->recipient_listing);
            // If null -> we need to left join and add a "is null" condition:
            if( ($k = array_search('null', $recipList)) !== FALSE ){
              $query->join('cf', "LEFT JOIN {$field["custom_group.table_name"]} cf ON e.id = cf.entity_id");
              unset($recipList[$k]);
              if( empty($recipList) ){ // get only null values
                $query->where("cf.{$field['column_name']} IS NULL");
              } else{ // null OR specific values
                $query->where("cf.{$field['column_name']} IS NULL OR cf.{$field['column_name']} IN (@recipList)")
                  ->param('recipList', $recipList);
              }
            } else{ // We can inner join to filter by the selected values
              $query->join('cf', "INNER JOIN {$field["custom_group.table_name"]} cf ON e.id = cf.entity_id");
              $query->where("cf.{$field['column_name']} IN (@recipList)")
                ->param('recipList', $recipList);
            }

          }
          break;
      }
    }

    // build where clause
    // FIXME: This handles scheduled reminder of type "Event Name" and "Event Type", gives incorrect result on "Event Template".
    if (!empty($selectedValues)) {
      $valueField = ($this->id == \CRM_Event_ActionMapping::EVENT_TYPE_MAPPING_ID) ? 'event_type_id' : 'id';
      $query->where("r.{$valueField} IN (@selectedValues)")
        ->param('selectedValues', $selectedValues);
    }
    else {
      $query->where(($this->id == \CRM_Event_ActionMapping::EVENT_TYPE_MAPPING_ID) ? "r.event_type_id IS NULL" : "r.id IS NULL");
    }

    $query->where('r.is_active = 1');
    $query->where('r.is_template = 0');

    // participant status criteria not to be implemented for additional recipients
    // ... why not?
    if (!empty($selectedStatuses)) {
      switch ($phase) {
        case RecipientBuilder::PHASE_RELATION_FIRST:
        case RecipientBuilder::PHASE_RELATION_REPEAT:
          $query->where("e.status_id IN (#selectedStatuses)")
            ->param('selectedStatuses', $selectedStatuses);
          break;

      }
    }

    return $query;
  }

  /**
   * Determine whether a schedule based on this mapping should
   * send to additional contacts.
   *
   * @param string $entityId Either an event ID/event type ID, or a set of event IDs/types separated
   *  by the separation character.
   */
  public function sendToAdditional($entityId): bool {
    $selectedValues = (array) \CRM_Utils_Array::explodePadded($entityId);
    switch ($this->id) {
      case self::EVENT_TYPE_MAPPING_ID:
        $valueTable = 'e';
        $valueField = 'event_type_id';
        $templateReminder = FALSE;
        break;

      case self::EVENT_NAME_MAPPING_ID:
        $valueTable = 'e';
        $valueField = 'id';
        $templateReminder = FALSE;
        break;

      case self::EVENT_TPL_MAPPING_ID:
        $valueTable = 't';
        $valueField = 'id';
        $templateReminder = TRUE;
        break;
    }
    // Don't send to additional recipients if this event is deleted or a template.
    $query = new \CRM_Utils_SQL_Select('civicrm_event e');
    $query
      ->select('e.id')
      ->where("e.is_template = 0")
      ->where("e.is_active = 1");
    if ($templateReminder) {
      $query->join('r', 'INNER JOIN civicrm_event t ON e.template_title = t.template_title AND t.is_template = 1');
    }
    $sql = $query
      ->where("{$valueTable}.{$valueField} IN (@selectedValues)")
      ->param('selectedValues', $selectedValues)
      ->toSQL();
    $dao = \CRM_Core_DAO::executeQuery($sql);
    return (bool) $dao->N;
  }

}

/**
 * Get Cutom Fields with options for Participants
 *
 * Get all custom fields with options (select, checkbox, radio) for participants
 * Cache it, since we will be using it multiple time during the scheduled reminder lifetime
 *
 * @param int $event_id the relevent event id (if any)
 * @return array
 **/
function _get_fields_with_options($event_id = null)
{

  $cache_key = 'custom_event_fields_with_options';
  // $bla = Civi::cache('short')->get($cache_key);
  // Civi::cache()->delete($cache_key);
  $custom_fields_with_options = Civi::cache('short')->get($cache_key);
  // Already cached
  if ( $custom_fields_with_options ){
    // return cached
    if( $event_id && isset( $custom_fields_with_options[ $event_id ] ) ) return $custom_fields_with_options[ $event_id ];
    elseif( isset( $custom_fields_with_options['all'] ) ) return $custom_fields_with_options['all'];
  } 
  else { // not cached

    $custom_fields_with_options_api = \Civi\Api4\CustomField::get()  
      ->addSelect('label', 'option_group.name', 'custom_group.table_name', 'column_name')
      ->setJoin([
        ['CustomGroup AS custom_group', TRUE, NULL, ['custom_group.id', '=', 'custom_group_id']], 
        ['OptionGroup AS option_group', TRUE, NULL, ['option_group.id', '=', 'option_group_id']],
      ])
      ->addWhere('custom_group.extends', '=', 'participant')
      ->addWhere('custom_group.is_active', '=', TRUE)
      ->addWhere('html_type', 'IN', ['Select', 'CheckBox', 'Radio'])
      ->addWhere('is_active', '=', TRUE);
    // Only for this event
    if( $event_id ) {
      $event_type = \Civi\Api4\Event::get()->addSelect('event_type_id')->addWhere('id', '=', $event_id)->execute()->single()['event_type_id'];
      $custom_fields_with_options_api->addClause('OR', 
        ['custom_group.extends_entity_column_id', 'IS NULL'], 
        ['custom_group.extends_entity_column_id', '=', 1], 
        ['AND', [['custom_group.extends_entity_column_id', '=', 3], ['custom_group.extends_entity_column_value', 'CONTAINS', $event_type]]], 
        ['AND', [['custom_group.extends_entity_column_id', '=', 2], ['custom_group.extends_entity_column_value', 'CONTAINS', $event_id]]]
      );
      $custom_fields_with_options[$event_id] = $custom_fields_with_options_api->execute()->indexBy('option_group.name')->getArrayCopy();
      // Save on cache - for this event
      Civi::cache('short')->set($cache_key, $custom_fields_with_options);
      return $custom_fields_with_options[$event_id];
    } else {
      $custom_fields_with_options['all'] = $custom_fields_with_options_api->execute()->indexBy('option_group.name')->getArrayCopy();
      // Save on cache - all
      Civi::cache('short')->set($cache_key, $custom_fields_with_options);
      return $custom_fields_with_options['all'];
    }
  }
}
