<?php
// +----------------------------------------------------------------------+
// | Anuko Time Tracker
// +----------------------------------------------------------------------+
// | Copyright (c) Anuko International Ltd. (https://www.anuko.com)
// +----------------------------------------------------------------------+
// | LIBERAL FREEWARE LICENSE: This source code document may be used
// | by anyone for any purpose, and freely redistributed alone or in
// | combination with other software, provided that the license is obeyed.
// |
// | There are only two ways to violate the license:
// |
// | 1. To redistribute this code in source form, with the copyright
// |    notice or license removed or altered. (Distributing in compiled
// |    forms without embedded copyright notices is permitted).
// |
// | 2. To redistribute modified versions of this code in *any* form
// |    that bears insufficient indications that the modifications are
// |    not the work of the original author(s).
// |
// | This license applies to this document only, not any other software
// | that it may be combined with.
// |
// +----------------------------------------------------------------------+
// | Contributors:
// | https://www.anuko.com/time_tracker/credits.htm
// +----------------------------------------------------------------------+

require_once('initialize.php');
import('form.Form');
import('form.DefaultCellRenderer');
import('form.Table');
import('form.TextField');
import('ttUserHelper');
import('ttTeamHelper');
import('ttClientHelper');
import('ttTimeHelper');
import('DateAndTime');

// Access check.
if (!ttAccessCheck(right_data_entry)) {
  header('Location: access_denied.php');
  exit();
}

// Initialize and store date in session.
$cl_date = $request->getParameter('date', @$_SESSION['date']);
$selected_date = new DateAndTime(DB_DATEFORMAT, $cl_date);
if($selected_date->isError())
  $selected_date = new DateAndTime(DB_DATEFORMAT);
if(!$cl_date)
  $cl_date = $selected_date->toString(DB_DATEFORMAT);
$_SESSION['date'] = $cl_date;

// Determine selected week start and end dates.
$weekStartDay = $user->week_start;
$t_arr = localtime($selected_date->getTimestamp());
$t_arr[5] = $t_arr[5] + 1900;
if ($t_arr[6] < $weekStartDay)
  $startWeekBias = $weekStartDay - 7;
else
  $startWeekBias = $weekStartDay;
$startDate = new DateAndTime();
$startDate->setTimestamp(mktime(0,0,0,$t_arr[4]+1,$t_arr[3]-$t_arr[6]+$startWeekBias,$t_arr[5]));
$endDate = new DateAndTime();
$endDate->setTimestamp(mktime(0,0,0,$t_arr[4]+1,$t_arr[3]-$t_arr[6]+6+$startWeekBias,$t_arr[5]));
// The above is needed to set date range (timestring) in page title.

// Use custom fields plugin if it is enabled.
if ($user->isPluginEnabled('cf')) {
  require_once('plugins/CustomFields.class.php');
  $custom_fields = new CustomFields($user->team_id);
  $smarty->assign('custom_fields', $custom_fields);
}

// TODO: how is this plugin supposed to work for week view?
if ($user->isPluginEnabled('mq')){
  require_once('plugins/MonthlyQuota.class.php');
  $quota = new MonthlyQuota();
  $month_quota = $quota->get($selected_date->mYear, $selected_date->mMonth);
  $month_total = ttTimeHelper::getTimeForMonth($user->getActiveUser(), $selected_date);
  $minutes_left = ttTimeHelper::toMinutes($month_quota) - ttTimeHelper::toMinutes($month_total);

  $smarty->assign('month_total', $month_total);
  $smarty->assign('over_quota', $minutes_left < 0);
  $smarty->assign('quota_remaining', ttTimeHelper::toAbsDuration($minutes_left));
}

// Initialize variables.
// Custom field.
$cl_cf_1 = trim($request->getParameter('cf_1', ($request->getMethod()=='POST'? null : @$_SESSION['cf_1'])));
$_SESSION['cf_1'] = $cl_cf_1;
$cl_billable = 1;
if ($user->isPluginEnabled('iv')) {
  if ($request->isPost()) {
    $cl_billable = $request->getParameter('billable');
    $_SESSION['billable'] = (int) $cl_billable;
  } else
    if (isset($_SESSION['billable']))
      $cl_billable = $_SESSION['billable'];
}
$on_behalf_id = $request->getParameter('onBehalfUser', (isset($_SESSION['behalf_id'])? $_SESSION['behalf_id'] : $user->id));
$cl_client = $request->getParameter('client', ($request->getMethod()=='POST'? null : @$_SESSION['client']));
$_SESSION['client'] = $cl_client;
$cl_project = $request->getParameter('project', ($request->getMethod()=='POST'? null : @$_SESSION['project']));
$_SESSION['project'] = $cl_project;
$cl_task = $request->getParameter('task', ($request->getMethod()=='POST'? null : @$_SESSION['task']));
$_SESSION['task'] = $cl_task;
$cl_note = trim($request->getParameter('note'));

// Get the data we need to display week view.
// Get column headers, which are day numbers in month.
$dayHeaders = ttTimeHelper::getDayHeadersForWeek($startDate->toString(DB_DATEFORMAT));
$lockedDays = ttTimeHelper::getLockedDaysForWeek($startDate->toString(DB_DATEFORMAT));
// Build data array for the table. Format is described in the function..
$dataArray = ttTimeHelper::getDataForWeekView($user->getActiveUser(), $startDate->toString(DB_DATEFORMAT), $endDate->toString(DB_DATEFORMAT), $dayHeaders);
// Build day totals (total durations for each day in week).
$dayTotals = ttTimeHelper::getDayTotals($dataArray, $dayHeaders);

// Define rendering class for a label field to the left of durations.
class LabelCellRenderer extends DefaultCellRenderer {
  function render(&$table, $value, $row, $column, $selected = false) {
    $this->setOptions(array('width'=>200,'valign'=>'middle'));
    // Special handling for row 0, which represents a new week entry.
    if (0 == $row) {
      $this->setOptions(array('style'=>'text-align: center; font-weight: bold;'));
    }
    // Special handling for not billable entries.
    if ($row > 0) {
      $row_id = $table->getValueAtName($row,'row_id');
      $billable = ttTimeHelper::parseFromWeekViewRow($row_id, 'bl');
      if (!$billable) {
        $this->setOptions(array('style'=>'color: red;')); // TODO: style it properly in CSS.
      }
    }
    $this->setValue(htmlspecialchars($value)); // This escapes HTML for output.
    return $this->toString();
  }
}

// Define rendering class for a single cell for time entry in week view table.
class TimeCellRenderer extends DefaultCellRenderer {
  function render(&$table, $value, $row, $column, $selected = false) {
    $field_name = $table->getValueAt($row,$column)['control_id']; // Our text field names (and ids) are like x_y (row_column).
    $field = new TextField($field_name);
    // Disable control if the date is locked.
    global $lockedDays;
    if ($lockedDays[$column-1])
      $field->setEnabled(false);
    $field->setFormName($table->getFormName());
    $field->setSize(2);
    $field->setValue($table->getValueAt($row,$column)['duration']);
    // Disable control when time entry mode is TYPE_START_FINISH and there is no value in control
    // because we can't supply start and finish times in week view - there are no fields for them.
    global $user;
    if (!$field->getValue() && TYPE_START_FINISH == $user->record_type) {
        $field->setEnabled(false);
    }
    $this->setValue($field->getHtml());
    return $this->toString();
  }
}

// Elements of weekTimeForm.
$form = new Form('weekTimeForm');

if ($user->canManageTeam()) {
  $user_list = ttTeamHelper::getActiveUsers(array('putSelfFirst'=>true));
  if (count($user_list) > 1) {
    $form->addInput(array('type'=>'combobox',
      'onchange'=>'this.form.submit();',
      'name'=>'onBehalfUser',
      'style'=>'width: 250px;',
      'value'=>$on_behalf_id,
      'data'=>$user_list,
      'datakeys'=>array('id','name')));
    $smarty->assign('on_behalf_control', 1);
  }
}

// Create week_durations table.
$table = new Table('week_durations');
// $table->setIAScript('markModified'); // TODO: write a script to mark table or particular cells as modified.
$table->setTableOptions(array('width'=>'100%','cellspacing'=>'1','cellpadding'=>'3','border'=>'0'));
$table->setRowOptions(array('class'=>'tableHeaderCentered'));
$table->setData($dataArray);
// Add columns to table.
$table->addColumn(new TableColumn('label', '', new LabelCellRenderer(), $dayTotals['label']));
for ($i = 0; $i < 7; $i++) {
  $table->addColumn(new TableColumn($dayHeaders[$i], $dayHeaders[$i], new TimeCellRenderer(), $dayTotals[$dayHeaders[$i]]));
}
$table->setInteractive(false);
$form->addInputElement($table);

// Dropdown for clients in MODE_TIME. Use all active clients.
if (MODE_TIME == $user->tracking_mode && $user->isPluginEnabled('cl')) {
  $active_clients = ttTeamHelper::getActiveClients($user->team_id, true);
  $form->addInput(array('type'=>'combobox',
    'onchange'=>'fillProjectDropdown(this.value);',
    'name'=>'client',
    'style'=>'width: 250px;',
    'value'=>$cl_client,
    'data'=>$active_clients,
    'datakeys'=>array('id', 'name'),
    'empty'=>array(''=>$i18n->getKey('dropdown.select'))));
  // Note: in other modes the client list is filtered to relevant clients only. See below.
}

if (MODE_PROJECTS == $user->tracking_mode || MODE_PROJECTS_AND_TASKS == $user->tracking_mode) {
  // Dropdown for projects assigned to user.
  $project_list = $user->getAssignedProjects();
  $form->addInput(array('type'=>'combobox',
    'onchange'=>'fillTaskDropdown(this.value);',
    'name'=>'project',
    'style'=>'width: 250px;',
    'value'=>$cl_project,
    'data'=>$project_list,
    'datakeys'=>array('id','name'),
    'empty'=>array(''=>$i18n->getKey('dropdown.select'))));

  // Dropdown for clients if the clients plugin is enabled.
  if ($user->isPluginEnabled('cl')) {
    $active_clients = ttTeamHelper::getActiveClients($user->team_id, true);
    // We need an array of assigned project ids to do some trimming.
    foreach($project_list as $project)
      $projects_assigned_to_user[] = $project['id'];

    // Build a client list out of active clients. Use only clients that are relevant to user.
    // Also trim their associated project list to only assigned projects (to user).
    foreach($active_clients as $client) {
      $projects_assigned_to_client = explode(',', $client['projects']);
      if (is_array($projects_assigned_to_client) && is_array($projects_assigned_to_user))
        $intersection = array_intersect($projects_assigned_to_client, $projects_assigned_to_user);
      if ($intersection) {
        $client['projects'] = implode(',', $intersection);
        $client_list[] = $client;
      }
    }
    $form->addInput(array('type'=>'combobox',
      'onchange'=>'fillProjectDropdown(this.value);',
      'name'=>'client',
      'style'=>'width: 250px;',
      'value'=>$cl_client,
      'data'=>$client_list,
      'datakeys'=>array('id', 'name'),
      'empty'=>array(''=>$i18n->getKey('dropdown.select'))));
  }
}

if (MODE_PROJECTS_AND_TASKS == $user->tracking_mode) {
  $task_list = ttTeamHelper::getActiveTasks($user->team_id);
  $form->addInput(array('type'=>'combobox',
    'name'=>'task',
    'style'=>'width: 250px;',
    'value'=>$cl_task,
    'data'=>$task_list,
    'datakeys'=>array('id','name'),
    'empty'=>array(''=>$i18n->getKey('dropdown.select'))));
}
$form->addInput(array('type'=>'textarea','name'=>'note','style'=>'width: 250px; height:'.NOTE_INPUT_HEIGHT.'px;','value'=>$cl_note));

// Add other controls.
$form->addInput(array('type'=>'calendar','name'=>'date','value'=>$cl_date)); // calendar
if ($user->isPluginEnabled('iv'))
  $form->addInput(array('type'=>'checkbox','name'=>'billable','value'=>$cl_billable));
$form->addInput(array('type'=>'hidden','name'=>'browser_today','value'=>'get_date()')); // User current date, which gets filled in on btn_submit click.
$form->addInput(array('type'=>'submit','name'=>'btn_submit','onclick'=>'browser_today.value=get_date()','value'=>$i18n->getKey('button.submit')));

// If we have custom fields - add controls for them.
if ($custom_fields && $custom_fields->fields[0]) {
  // Only one custom field is supported at this time.
  if ($custom_fields->fields[0]['type'] == CustomFields::TYPE_TEXT) {
    $form->addInput(array('type'=>'text','name'=>'cf_1','value'=>$cl_cf_1));
  } elseif ($custom_fields->fields[0]['type'] == CustomFields::TYPE_DROPDOWN) {
    $form->addInput(array('type'=>'combobox','name'=>'cf_1',
      'style'=>'width: 250px;',
      'value'=>$cl_cf_1,
      'data'=>$custom_fields->options,
      'empty'=>array(''=>$i18n->getKey('dropdown.select'))));
  }
}

// Submit.
if ($request->isPost()) {
  if ($request->getParameter('btn_submit')) {
    // Validate user input for row 0.
    // Determine if a new entry was posted.
    $newEntryPosted = false;
    foreach($dayHeaders as $dayHeader) {
      $control_id = '0_'.$dayHeader;
      if ($request->getParameter($control_id)) {
        $newEntryPosted = true;
        break;
      }
    }
    if ($newEntryPosted) {
      if ($user->isPluginEnabled('cl') && $user->isPluginEnabled('cm') && !$cl_client)
        $err->add($i18n->getKey('error.client'));
      if ($custom_fields) {
        if (!ttValidString($cl_cf_1, !$custom_fields->fields[0]['required'])) $err->add($i18n->getKey('error.field'), $custom_fields->fields[0]['label']);
      }
      if (MODE_PROJECTS == $user->tracking_mode || MODE_PROJECTS_AND_TASKS == $user->tracking_mode) {
        if (!$cl_project) $err->add($i18n->getKey('error.project'));
      }
      if (MODE_PROJECTS_AND_TASKS == $user->tracking_mode && $user->task_required) {
        if (!$cl_task) $err->add($i18n->getKey('error.task'));
      }
    }

    // Process the table of values.
    if ($err->no()) {

      // Obtain values. Perhaps, it's best to iterate throigh posted parameters one by one,
      // see if anything changed, and apply one change at a time until we see an error.
      $result = true;
      $rowNumber = 0;
      // Iterate through existing rows.
      foreach ($dataArray as $row) {
        // Iterate through days.
        foreach ($dayHeaders as $key => $dayHeader) {
          // Do not process locked days.
          if ($lockedDays[$key]) continue;
          // Make control id for the cell.
          $control_id = $rowNumber.'_'.$dayHeader;
          // Optain existing and posted durations.
          $postedDuration = $request->getParameter($control_id);
          $existingDuration = $dataArray[$rowNumber][$dayHeader]['duration'];
          // If posted value is not null, check and normalize it.
          if ($postedDuration) {
            if (ttTimeHelper::isValidDuration($postedDuration)) {
              $postedDuration = ttTimeHelper::normalizeDuration($postedDuration, false); // No leading zero.
            } else {
              $err->add($i18n->getKey('error.field'), $i18n->getKey('label.duration'));
              $result = false; break; // Break out. Stop any further processing.
            }
          }
          // Do not process if value has not changed.
          if ($postedDuration == $existingDuration)
            continue;
          // Posted value is different.
          if ($existingDuration == null) {
            // Skip inserting 0 duration values.
            if (0 == ttTimeHelper::toMinutes($postedDuration))
              continue;
            // Insert a new record.
            $fields = array();
            $fields['row_id'] = $dataArray[$rowNumber]['row_id'];
            if (!$fields['row_id']) {
              // Special handling for row 0, a new entry. Need to construct row_id.
              $record = array();
              $record['client_id'] = $cl_client;
              $record['billable'] = $cl_billable ? '1' : '0';
              $record['project_id'] = $cl_project;
              $record['task_id'] = $cl_task;
              $record['cf_1_value'] = $cl_cf_1;
              $fields['row_id'] = ttTimeHelper::makeRecordIdentifier($record).'_0';
              $fields['note'] = $cl_note;
            }
            $fields['day_header'] = $dayHeader;
            $fields['start_date'] = $startDate->toString(DB_DATEFORMAT); // To be able to determine date for the entry using $dayHeader.
            $fields['duration'] = $postedDuration;
            $fields['browser_today'] = $request->getParameter('browser_today', null);
            $result = ttTimeHelper::insertDurationFromWeekView($fields, $custom_fields, $err);
          } elseif ($postedDuration == null || 0 == ttTimeHelper::toMinutes($postedDuration)) {
            // Delete an already existing record here.
            $result = ttTimeHelper::delete($dataArray[$rowNumber][$dayHeader]['tt_log_id'], $user->getActiveUser());
          } else {
            $fields = array();
            $fields['tt_log_id'] = $dataArray[$rowNumber][$dayHeader]['tt_log_id'];
            $fields['duration'] = $postedDuration;
            $result = ttTimeHelper::modifyDurationFromWeekView($fields, $err);
          }
          if (!$result) break; // Break out of the loop in case of first error.
        }
        if (!$result) break; // Break out of the loop in case of first error.
        $rowNumber++;
      }
      if ($result) {
        header('Location: week.php'); // Normal exit.
        exit();
      }
    }
  }
  elseif ($request->getParameter('onBehalfUser')) {
    if($user->canManageTeam()) {
      unset($_SESSION['behalf_id']);
      unset($_SESSION['behalf_name']);

      if($on_behalf_id != $user->id) {
        $_SESSION['behalf_id'] = $on_behalf_id;
        $_SESSION['behalf_name'] = ttUserHelper::getUserName($on_behalf_id);
      }
      header('Location: week.php');
      exit();
    }
  }
} // isPost

$week_total = ttTimeHelper::getTimeForWeek($user->getActiveUser(), $selected_date);

$smarty->assign('selected_date', $selected_date);
$smarty->assign('week_total', $week_total);

$smarty->assign('client_list', $client_list);
$smarty->assign('project_list', $project_list);
$smarty->assign('task_list', $task_list);
$smarty->assign('forms', array($form->getName()=>$form->toArray()));
$smarty->assign('onload', 'onLoad="fillDropdowns()"');
$smarty->assign('timestring', $startDate->toString($user->date_format).' - '.$endDate->toString($user->date_format));

$smarty->assign('title', $i18n->getKey('title.time'));
$smarty->assign('content_page_name', 'week.tpl');
$smarty->display('index.tpl');
