<?php
use CRM_EventRsvpReminders_ExtensionUtil as E;

class CRM_EventRsvpReminders_Page_RSVPPage extends CRM_Core_Page {

  public function run() {

    CRM_Utils_System::setTitle( E::ts('Thank you') );

    $error = false;
    $err_msg = array();

    if( isset($_GET['a']) &&
        isset($_GET['r']) && 
        isset($_GET['pid']) &&
        isset($_GET['cid']) &&
        isset($_GET['cs']) ) {
      
      $action = $_GET['a'];
      $response = $_GET['r'];
      $pid = $_GET['pid'];
      
      // Validate checksum
      if( CRM_Contact_BAO_Contact_Utils::validChecksum($_GET['cid'], $_GET['cs']) ){

        // Checksum matches: we can trust this call - checkPermissions = FALSE
        try{
          $api_call = \Civi\Api4\Participant::update(FALSE)
            ->addWhere('id', '=', $pid)
            ->addValue("gEvent_Invitation.$action", $response)
            ->addValue("gEvent_Invitation.{$action}_Date", (new DateTime())->format('Y-m-d h:i:s') )
            ->execute();         	
        } catch( Exception $th ){
          $error = true;
          $err_msg[] = $th->getMessage();  
        }

      } else{
        $error = true;
        $err_msg[] = "Checksum failed";  
      }
    } else{
      $error = true;
      $err_msg[] = "Missing parameters";
    }

    if( $error ){      
      // Log
      Civi::log()->error( 'Event RSVP: ' . implode(PHP_EOL, $err_msg) . PHP_EOL . "GET:" . print_r($_GET, true), array('Event RSVP', __CLASS__), );
      // Get event admin email (if exists)
      $event_emails = $participants = \Civi\Api4\Participant::get(FALSE)
        ->addSelect('email.email')
        ->setJoin([
          ['Event AS event', TRUE, NULL, ['event.id', '=', 'event_id']], 
          ['LocBlock AS loc_block', TRUE, NULL, ['loc_block.id', '=', 'event.loc_block_id']], 
          ['Email AS email', FALSE, NULL, ['OR', [['email.id', '=', 'loc_block.email_id'], ['email.id', '=', 'loc_block.email_2_id']]]],
        ])
        ->addWhere('id', '=', $pid)
        ->execute();
      if( isset($event_emails[0]['email.email']) ) $admin_email = $event_emails[0]['email.email'];
      elseif( isset($event_emails[1]['email.email']) ) $admin_email = $event_emails[1]['email.email'];
      
      $this->assign("error", $error);
      if($admin_email) $this->assign("admin_email", $admin_email);
    }

    parent::run();
  }

}
