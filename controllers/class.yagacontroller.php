<?php

if(!defined('APPLICATION'))
  exit();
/* Copyright 2013 Zachary Doll */

/**
 * Manage the yaga application including configuration and import/export
 *
 * @since 1.0
 * @package Yaga
 */
class YagaController extends DashboardController {

  /**
   * @var array These objects will be created on instantiation and available via
   * $this->ObjectName
   */
  public $Uses = array('Form');

  /**
   * Make this look like a dashboard page and add the resources
   *
   * @since 1.0
   * @access public
   */
  public function Initialize() {
    parent::Initialize();
    $this->Application = 'Yaga';
    Gdn_Theme::Section('Dashboard');
    if($this->Menu) {
      $this->Menu->HighlightRoute('/yaga');
    }
    $this->AddSideMenu('yaga/settings');

    $this->AddCssFile('yaga.css');
  }

  /**
   * Redirect to settings by default
   */
  public function Index() {
    $this->Settings();
  }

  /**
   * This handles all the core settings for the gamification application.
   *
   */
  public function Settings() {
    $this->Permission('Garden.Settings.Manage');
    $this->Title(T('Yaga.Settings'));

    // Get list of actions from the model and pass to the view
    $ConfigModule = new ConfigurationModule($this);

    $ConfigModule->Initialize(array(
        'Yaga.Reactions.Enabled' => array(
            'LabelCode' => 'Use Reactions',
            'Control' => 'Checkbox'
        ),
        'Yaga.Badges.Enabled' => array(
            'LabelCode' => 'Use Badges',
            'Control' => 'Checkbox'
        ),
        'Yaga.Ranks.Enabled' => array(
            'LabelCode' => 'Use Ranks',
            'Control' => 'Checkbox'
        ),
        'Yaga.LeaderBoard.Enabled' => array(
            'LabelCode' => 'Show leaderboard on activity page',
            'Control' => 'Checkbox'
        ),
        'Yaga.LeaderBoard.Limit' => array(
            'LabelCode' => 'Maximum number of leaders to show',
            'Control' => 'Textbox',
            'Options' => array(
                'Size' => 45,
                'class' => 'SmallInput'
            )
        )
    ));
    $this->ConfigurationModule = $ConfigModule;

    $this->Render('settings');
  }

  public function Import() {
    $this->Title(T('Yaga.Import'));
    $this->SetData('TransportType', 'Import');
    // Todo: Implement
    $this->Render('import');
  }

  public function Export() {
    $this->Title(T('Yaga.Export'));
    $this->SetData('TransportType', 'Export');

    if($this->Form->IsPostBack()) {
      $FormValues = $this->Form->FormValues();
      $Sections = $FormValues['Checkboxes'];
      
      // Figure out which boxes were checked
      $Include = array();
      foreach($Sections as $Section) {
        $Include[$Section] = $FormValues[$Section];
      }
      if(count($Include)) {
        if(class_exists('ZipArchive')) {
          $Filename = $this->_ExportAuto($Include);
          $this->SetData('TransportPath', $Filename);
          $this->Render('transport-success');
          return;
        }
        else {
          $this->Form->AddError('You do not seem to have the minimum requirements to export your Yaga configuration automatically. Please reference manual_export.md for more information.');
        }
      }
      else {
        $this->Form->AddError('You must select at least one item to export.');
      }
      
    }
    $this->Render('export');
  }
  
  protected function _ExportAuto($Include = array(), $Path = NULL) {
    $StartTime = microtime(TRUE);
    $Info = new stdClass();
    $Info->Version = C('Yaga.Version', '?.?');
    $Info->StartDate = date('Y-m-d H:i:s');
    
    if(is_null($Path)) {
      $Path = PATH_UPLOADS . DS . 'export' . date('Y-m-d His') . '.yaga.zip';
    }
    $FH = new ZipArchive();
    $Images = array();
    $Hashes = array();
    
    if($FH->open($Path, ZipArchive::CREATE) != TRUE) {
      $this->Form->AddError('Unable to create archive: ' . $FH->getStatusString());
      return FALSE;
    }
    
    // Add actions
    if($Include['Actions']) {
      $Info->Actions = 'actions.yaga';
      $Actions = Yaga::ActionModel()->Get('Sort', 'asc');
      $ActionData = serialize($Actions);
      $FH->addFromString('actions.yaga', $ActionData);
      array_push($Hashes, md5($ActionData));
    }
    
    // Add ranks and associated image
    if($Include['Ranks']) {
      $Info->Ranks = 'ranks.yaga';
      $Ranks = Yaga::RankModel()->Get('Level', 'asc');
      $RankData = serialize($Ranks);
      $FH->addFromString('ranks.yaga', $RankData);
      array_push($Images, C('Yaga.Ranks.Photo'), NULL);
      array_push($Hashes, md5($RankData));
    }
    
    // Add badges and associated images
    if($Include['Badges']) {
      $Info->Badges = 'badges.yaga';
      $Badges = Yaga::BadgeModel()->Get();
      $BadgeData = serialize($Badges);
      $FH->addFromString('badges.yaga', $BadgeData);
      array_push($Hashes, md5($BadgeData));
      foreach($Badges as $Badge) {
        array_push($Images, $Badge->Photo);
      }
    }
    
    // Add in any images
    $FilteredImages = array_filter($Images);
    if(count($FilteredImages)) {
      $FH->addEmptyDir('images');
    }
    
    foreach($FilteredImages as $Image) {
      if($FH->addFile(PATH_UPLOADS . DS . $Image, 'images' . DS . $Image) != TRUE) {
        $this->Form->AddError('Unable to add file: ' . $FH->getStatusString());
        return FALSE;
      }
      array_push($Hashes, md5($Image));
    }
    
    // Save all the hashes
    sort($Hashes);
    $Info->MD5 = md5(implode(',', $Hashes));
    $Info->EndDate = date('Y-m-d H:i:s');
    
    $EndTime = microtime(TRUE);
    $TotalTime = $EndTime - $StartTime;
    $m = floor($TotalTime / 60);
    $s = $TotalTime - ($m * 60);

    $Info->ElapsedTime = sprintf('%02d:%02.2f', $m, $s);
    
    $FH->setArchiveComment(serialize($Info));
    if($FH->close()) {
      return $Path;
    }
    else {
      $this->Form->AddError('Unable to save archive: ' . $FH->getStatusString());
      return FALSE;
    }
  }
}
