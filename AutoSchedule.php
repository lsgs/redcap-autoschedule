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
        private $pid = null;
        private $Proj;
        private $schedulingEnabled = false;
        private $triggerEvent = null;
        private $triggerField = null;
        
        /** 
         * Generate schedule when required.
         */
        public function redcap_save_record($project_id, $record=null, $instrument, $event_id, $group_id=null, $survey_hash=null, $response_id=null, $repeat_instance=1) {
                global $Proj;
                $this->pid = db_escape(PROJECT_ID);
                $this->Proj = $Proj;
                $this->schedulingEnabled = (bool)db_result(db_query('select scheduling from redcap_projects where project_id='.$this->pid), 0);
                $this->triggerEvent = $this->getProjectSetting('event-name');
                $this->triggerField = $this->getProjectSetting('field-name');
                
                $validate = $this->validateConfig();
                if ($validate!==true) { 
                    \REDCap::logEvent('Auto-Schedule external module', 'Invalid configuration detected: '.PHP_EOL.print_r($validate, true), '', $record, $event_id);
                    return; 
                }
                
                // Does the record have a schedule already?
                $result = db_result(db_query("select 1 from redcap_events_calendar where project_id=".db_escape($this->pid)." and record='".db_escape($record)."' "), 0);

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
                $dag = db_result(db_query("select value from redcap_data where project_id=".db_escape($this->pid)." and record='".db_escape($record)."' and field_name='__GROUPID__'"), 0);
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
}