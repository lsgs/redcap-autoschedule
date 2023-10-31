<?php
/**
 * REDCap External Module: Test Module
 * Luke's module for temporary test code.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\AutoSchedule;

use ExternalModules\AbstractExternalModule;
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
        
        protected function initModule() {
                if (defined('PROJECT_ID')) {
                        global $Proj;
                        $this->page = $this->escape(PAGE);
                        $this->pid = $this->escape(PROJECT_ID);
                        $this->Proj = $Proj;
                        $this->schedulingEnabled = (bool)db_result(db_query('select scheduling from redcap_projects where project_id='.$this->pid), 0);
                        $this->triggerEvent = $this->getProjectSetting('event-name');
                        $this->triggerField = $this->getProjectSetting('field-name');
                }
        }
        
        /**
         * Check configuration and provide warnings when required.
         * @param int $project_id
         */
        public function redcap_every_page_top($project_id) {
                $this->initModule();
                if (strpos($this->page, 'manager/project.php')!==false || strpos($this->page, 'ProjectSetup/index.php')!==false) {
                        $configCheck = $this->validateConfig();
                        
                        if ($configCheck!==true && strpos($this->page, 'manager/project.php')!==false) {
                                $this->printManagerPageContent($configCheck);
                        } else if ($configCheck!==true && strpos($this->page, 'ProjectSetup/index.php')!==false) {
                                $this->printSetupPageContent($configCheck);
                        }
                }
        }
        
        /** 
         * Generate schedule when required.
         */
        public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
                $this->initModule();
                if (true!==$this->validateConfig()) { return; }
                
                // Does the record have a schedule already?
                $result = db_result($this->query("select 1 from redcap_events_calendar where project_id=? and record=? ", [$this->pid,$record]), 0);

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
                $redcap_data = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($this->pid) : "redcap_data"; 
                $dag = db_result($this->query("select value from $redcap_data where project_id=? and record=? and field_name='__GROUPID__'", [$this->pid, $record]), 0);
                $dag = ($dag) ? $dag : 'null';

                
                // Generate a new schedule
                $sql = "insert into redcap_events_calendar (record, project_id, event_id,baseline_date,group_id,event_date,event_time,event_status) ".
                "select '".$this->escape($record)."', ".$this->escape($this->pid).", event_id, '".$this->escape($base)."', ".$this->escape($dag).", STR_TO_DATE( '".$this->escape($base)."', '%Y-%m-%d' ) + INTERVAL day_offset DAY, '', 0 
                from redcap_events_metadata 
                where arm_id = (select arm_id from redcap_events_metadata where event_id = ".$this->escape($event_id)." )";

                $result = $this->query($sql, []);

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
                if (!$this->Proj->longitudinal) {
                        $errors[] = '<strong>This is not a longitudinal project!</strong>'; 
                } else if (!$this->schedulingEnabled) {
                        $errors[] = '<strong>Scheduling is not enabled</strong> for this project.'; 
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
    <div style="font-size:120%"><strong><i class="fas fa-cube mr-1"></i>Auto-Schedule External Module Errors</strong></div>
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