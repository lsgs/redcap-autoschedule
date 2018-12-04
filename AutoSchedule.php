<?php
/**
 * REDCap External Module: Test Module
 * Luke's module for temporary test code.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\AutoSchedule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Logging;
use REDCap;

class AutoSchedule extends AbstractExternalModule
{
        private $page;
        private $pid = null;
        private $Proj;
        private $schedulingEnabled = false;
        private $triggerEvent = null;
        private $triggerField = null;
        
        public function __construct() {
                parent::__construct();
                if (defined('PROJECT_ID')) {
                        global $Proj;
                        $this->page = PAGE;
                        $this->pid = db_escape(PROJECT_ID);
                        $this->Proj = $Proj;
                        $this->schedulingEnabled = (bool)db_result(db_query('select scheduling from redcap_projects where project_id='.$this->pid));
                        $this->triggerEvent = $this->getProjectSetting('event-name');
                        $this->triggerField = $this->getProjectSetting('field-name');
                }
        }
        
        /** 
         * Prevent enabling if scheduling not enabled for project.
         * Admins notified of failure via email, not onscreen. There seems no other way.
         * @param type $version
         * @param type $project_id
         */
        public function redcap_module_project_enable($version, $project_id) {
                if (!$this->schedulingEnabled) {
                        // reverse the module enable!
                        $this->setProjectSetting(ExternalModules::KEY_ENABLED, false, $project_id);
                        throw new Exception("Scheduling is not enabled for project $project_id.");
                }
        }
        
        /**
         * Check configuration and provide warnings when required.
         * @param int $project_id
         */
        public function redcap_every_page_top($project_id) {
                if (strpos($this->page, 'ExternalModules/manager/project.php')>0 || strpos($this->page, 'ProjectSetup/index.php')!==false) {
                        $configCheck = $this->validateConfig();
                        
                        if ($configCheck!==true && strpos($this->page, 'ExternalModules/manager/project.php')>0) {
                                $this->printManagerPageContent($configCheck);
                        } else if ($configCheck!==true && strpos($this->page, 'ProjectSetup/index.php')!==false) {
                                $this->printSetupPageContent($configCheck);
                        }
                }
        }
        
        /** 
         * Generate schedule when required.
         */
        public function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
                if (true!==$this->validateConfig()) { return; }
                
                // Does the record have a schedule already?
                $result = db_result(db_query("select 1 from redcap_events_calendar where project_id=".db_escape($this->pid)." and record='".db_escape($record)."' "));

                if ($result) { return; }

                $base= '';
                
                if (is_null($this->triggerEvent) && is_null($this->triggerField)) {
                        
                        // no particular event or field, baseline date is today
                        $base = TODAY;
                        
                } else if (!is_null($this->triggerEvent) && is_null($this->triggerField)) {
                        
                        // trigger event only 
                        // - if not current event, return, 
                        // - if current event then baseline date is today
                        if ($event_id != $this->triggerEvent) { return; }
                    
                        $base = TODAY;
                        
                } else {
                        
                        // trigger field specified - read the data
                        $recData = REDCap::getData(array(
                                'return_format' => 'array',
                                'records' => $record,
                                'fields' => $this->triggerField,
                                'events' => $this->triggerEvent // may be null
                        ));
                        
                        if (!is_null($this->triggerEvent)) {
                                // trigger event & field: get specific event/field value
                                $base = $recData[$record][$this->triggerEvent][$this->triggerField];
                        } else {
                                // trigger field only: find _first_ non-empty value in trigger field
                                foreach ($recData[$record] as $evt) {
                                        $base = $evt[$this->triggerField];
                                        if ($base !== '') { break;}
                                }
                        }
                }

                if (empty($base)) { return;}

                // remove any time component of base date 
                $base = substr($base, 0, 10);
                
                // Get DAG id, if applicable (no plugin method for this!)
                $dag = db_result(db_query("select value from redcap_data where project_id=".db_escape($this->pid)." and record='".db_escape($record)."' and field_name='__GROUPID__'"));
                $dag = ($dag) ? $dag : 'null';

                
                // Generate a new schedule
                $sql = "insert into redcap_events_calendar (record, project_id, event_id,baseline_date,group_id,event_date,event_time,event_status) ".
                "select '".db_escape($record)."', ".db_escape($this->pid).", event_id, '".db_escape($base)."', ".db_escape($dag).", STR_TO_DATE( '".db_escape($base)."', '%Y-%m-%d' ) + INTERVAL day_offset DAY, '', 0 
                from redcap_events_metadata 
                where arm_id = (select arm_id from redcap_events_metadata where event_id = ".db_escape($event_id)." )";

                $result = db_query($sql);

                if (db_insert_id() > 0) {
                    Logging::logEvent($sql,'redcap_events_calendar','INSERT',$record,"Baseline date = $base","Schedule generated");
                } else {
                    Logging::logEvent($sql,'redcap_events_calendar','INSERT',$record,"Baseline date = $base","Schedule generation failed");
                }
                return;
        }

        /**
         * Check config:
         * 1. Scheduling is enabled for project.
         * 2. Trigger field (if set) is a date field.
         * 3. Trigger event and field (if both set) are a valid combination.
         */
        protected function validateConfig() {
                $errors = array();
                if (!$this->schedulingEnabled) {
                        $errors[] = '<strong>Scheduling is not enabled</strong> for this project.'; //throw new Exception('Scheduling is not enabled for this project.');
                }
                
                if (!is_null($this->triggerField)) {
                        $triggerFieldType = $this->Proj->metadata[$this->triggerField]['element_validation_type'];
                        if (substr($triggerFieldType, 0, 4) !== 'date') {
                                $errors[] = "<strong>Trigger field must be a date or datetime field</strong>. '<span class='text-monospace'>{$this->triggerField}</span>' is of type '$triggerFieldType'.";
                        }
                }
                
                if (!is_null($this->triggerEvent) && !is_null($this->triggerField)) {
                        $ef = REDCap::getValidFieldsByEvents ( $this->pid, $this->triggerEvent );
                        if (!in_array($this->triggerField, $ef)) {
                                $errors[] = "<strong>Invalid event / field combination</strong>: field '{$this->triggerField}' does not exist on a form within event '".REDCap::getEventNames(false, true, $this->triggerEvent)."'.";
                        }
                }
                return (count($errors)>0) ? $errors : true;
        }
        
        protected function printManagerPageContent($configCheck) {
                if (!is_array($configCheck)) { $configCheck = array((string)$configCheck); }
                ?>
<div id="MCRI_AutoSchedule_AutoSchedule_Msg" style="display:none;" class="red">
    <div style="font-size:110%"><strong>Errors in Module Configuration!</strong></div>
    <?php echo implode('<br>', $configCheck);?>
</div>
<script type="text/javascript">
    $(window).on('load', function() {
        $('#MCRI_AutoSchedule_AutoSchedule_Msg')
            .appendTo('tr[data-module=autoschedule] td:first')
            .fadeIn();
    });
</script>
                <?php
        }
        
        protected function printSetupPageContent($configCheck) {
                if (!is_array($configCheck)) { $configCheck = array((string)$configCheck); }
                ?>
<div id="MCRI_AutoSchedule_AutoSchedule_Msg" style="display:none;font-size:85%;" class="red">
    <div style="font-size:120%"><img src="<?php echo APP_PATH_IMAGES.'puzzle_small.png';?>"> <strong>Auto-Schedule External Module Errors</strong></div>
    <?php echo implode('<br>', $configCheck);?>
</div>
<script type="text/javascript">
    $(window).on('load', function() {
        //$('button[onclick*="scheduling"]').parent('div').append($('#MCRI_AutoSchedule_AutoSchedule_Msg'));
        //$('#MCRI_AutoSchedule_AutoSchedule_Msg').fadeIn();
        //var schedDiv = $('button[onclick*="scheduling"]').parent('div');
        $('#MCRI_AutoSchedule_AutoSchedule_Msg')
            .insertAfter($('button[onclick*="scheduling"]').parent('div'))
            .fadeIn();
    });
</script>
        <?php
        }
}