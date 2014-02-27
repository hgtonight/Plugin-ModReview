<?php if(!defined('APPLICATION')) exit();
/* 	Copyright 2014 Zachary Doll
 * 	This program is free software: you can redistribute it and/or modify
 * 	it under the terms of the GNU General Public License as published by
 * 	the Free Software Foundation, either version 3 of the License, or
 * 	(at your option) any later version.
 *
 * 	This program is distributed in the hope that it will be useful,
 * 	but WITHOUT ANY WARRANTY; without even the implied warranty of
 * 	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * 	GNU General Public License for more details.
 *
 * 	You should have received a copy of the GNU General Public License
 * 	along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
$PluginInfo['ModReview'] = array(
    'Name' => 'Mod Review',
    'Description' => 'Adds the ability for moderators to communicate to other mods that a post was reviewed and they deem it acceptable.',
    'Version' => '0.1',
    'RequiredApplications' => array('Vanilla' => '2.0.18.10'),
    'RequiredTheme' => FALSE,
    'RequiredPlugins' => FALSE,
    'MobileFriendly' => TRUE,
    'HasLocale' => TRUE,
    'RegisterPermissions' => FALSE,
    'SettingsUrl' => '/settings/modreview',
    'SettingsPermission' => 'Garden.Settings.Manage',
    'Author' => 'Zachary Doll',
    'AuthorEmail' => 'hgtonight@daklutz.com',
    'AuthorUrl' => 'http://www.daklutz.com',
    'License' => 'GPLv3'
);

class ModReview extends Gdn_Plugin {

  /**
   * Handles settings screen
   */
  public function SettingsController_ModReview_Create($Sender) {
    $Sender->AddSideMenu('settings/modreview');

    $Sender->Title($this->GetPluginName() . ' ' . T('Settings'));
    $Sender->Render($this->GetView('settings.php'));
  }

  /**
   * Handles the mod review actions
   */
  public function PostController_ModReview_Create($Sender) {
    $this->Dispatch($Sender, $Sender->RequestArgs);
  }

  /**
   * Default to adding a review
   */
  public function Controller_Index($Sender) {
    $this->Controller_Add($Sender);
  }

  /**
   * Mark the post as reviewed
   */
  public function Controller_Add($Sender) {
    $this->_UpdateReview($Sender, date(DATE_ISO8601), Gdn::Session()->UserID);
  }

  /**
   * Clear the post review from the db
   */
  public function Controller_Clear($Sender) {
    $this->_UpdateReview($Sender, 'NULL', 'NULL');
  }

  /**
   * This sets the review data to whatever is passed and redirects to the post
   * that was operated on.
   *
   * @param PluginController $Sender
   * @param string $Date Date the review occurred
   * @param int $UserID ID of the reviewer
   */
  private function _UpdateReview($Sender, $Date, $UserID) {
    // Remove the review by nulling the object's review columns
    $Session = Gdn::Session();

    if(!$Session->CheckPermission('Garden.Moderation.Manage')) {
      return;
    }

    // Add the review by filling out the object's review columns
    $Args = $Sender->RequestArgs;
    $ObjectType = GetValue(1, $Args, FALSE);
    $ObjectID = GetValue(2, $Args, FALSE);
    $Path = FALSE;

    $SQL = Gdn::SQL();
    if($ObjectID && $ObjectType) {
      switch($ObjectType) {
        case 'comment':
          $SQL->Update('Comment')
                  ->Set('ModReviewDate', $Date)
                  ->Set('ModReviewUserID', $UserID)
                  ->Where('CommentID', $ObjectID)
                  ->Put();
          $Path = '/discussion/comment/' . $ObjectID;
          break;
        case 'discussion':
          $SQL->Update('Discussion')
                  ->Set('ModReviewDate', $Date)
                  ->Set('ModReviewUserID', $UserID)
                  ->Where('DiscussionID', $ObjectID)
                  ->Put();
          $Path = '/discussion/' . $ObjectID;
          break;
        default:
          break;
      }
    }

    if(!$Path) {
      throw new Gdn_UserException('Unable to review that post or know where you came from!');
    }
    else {
      Redirect($Path);
    }
  }

  /**
   * Add in that CSS file
   */
  public function DiscussionController_Render_Before($Sender) {
    $this->_AddResources($Sender);
  }

  /**
   * Add a CSS class to items that have been reviewed for moderators only
   */
  public function DiscussionController_BeforeCommentDisplay_Handler($Sender, $Args) {
    $Session = Gdn::Session();
    if(!$Session->CheckPermission('Garden.Moderation.Manage')) {
      return;
    }

    $Review = $this->_GetReviewObject($Args);

    if($Review->Date != FALSE) {
      $Sender->EventArguments['CssClass'] .= ' ModReviewed';
    }
  }

  public function DiscussionController_AfterDiscussionMeta_Handler($Sender, $Args) {
    $this->_AddReviewButton($Sender, $Args);
  }

  public function DiscussionController_InsideCommentMeta_Handler($Sender, $Args) {
    $this->_AddReviewButton($Sender, $Args);
  }

  public function DiscussionController_CommentOptions_Handler($Sender, $Args) {
    if(!version_compare(APPLICATION_VERSION, '2.1b2', '>=')) {
      $this->_AddReviewButton($Sender, $Args);
    }
  }

  /**
   * Add an appropriate mod review link. Unreviewed posts get a 'Mark Reviewed'
   * link. Reviewed posts get a 'Remove Your Review' link or a 'Reviewed by User'
   * link, depending on what user reviewed the post (You or someone else).
   */
  private function _AddReviewButton($Sender, $Args) {
    $Session = Gdn::Session();
    if(!$Session->CheckPermission('Garden.Moderation.Manage')) {
      return;
    }

    $Review = $this->_GetReviewObject($Args);
    if($Review->Date) {
      if($Review->UserID == $Session->UserID) {
        // Show a link to remove the review if it is the current user
        echo Wrap(Anchor(T('Plugins.ModReview.Remove'), Url("post/modreview/clear/{$Review->Type}/{$Review->TypeID}", TRUE)));
      }
      else {
        // Load up the username of the reviewer
        $Reviewer = Gdn::UserModel()->GetID($Review->UserID);

        // Show a link to message the user if it is not the current user
        echo Wrap(
                Anchor(
                        sprintf(T('Plugins.ModReview.ReviewedOther'), $Reviewer->Name), Url('messages/add/' . $Reviewer->Name)
                ), 'span', array(
            'title' => sprintf(T('Plugins.ModReview.SendMessage'), $Reviewer->Name, $Review->Date)
                )
        );
      }
    }
    else {
      // Show a link to review the post in question
      echo Wrap(Anchor(T('Plugins.ModReview.Mark'), Url("post/modreview/add/{$Review->Type}/{$Review->TypeID}", TRUE)));
    }
  }

  /**
   * This takes in an object and parses it to construct a review object and
   * determine the post type
   */
  private function _GetReviewObject($Args) {
    $Return = new stdClass();
    if(isset($Args['Comment'])) {
      $Return->Type = 'comment';
      $Return->TypeID = $Args['Comment']->CommentID;
      $Object = $Args['Comment'];
    }
    else if(isset($Args['Discussion'])) {
      $Return->Type = 'discussion';
      $Return->TypeID = $Args['Discussion']->DiscussionID;
      $Object = $Args['Discussion'];
    }
    else {
      $Return->Type = FALSE;
      $Return->TypeID = FALSE;
      $Object = new stdClass();
      $Object->ModReviewUserID = FALSE;
    }

    if($Object->ModReviewUserID) {
      $Return->UserID = $Object->ModReviewUserID;
      $Return->Date = $Object->ModReviewDate;
    }
    else {
      $Return->UserID = FALSE;
      $Return->Date = FALSE;
    }
    return $Return;
  }

  /**
   * Single point to add in external resources
   */
  private function _AddResources($Sender) {
    $Sender->AddCssFile($this->GetResource('design/modreview.css', FALSE, FALSE));
  }

  /**
   * Add the review data to the db.
   */
  public function Structure() {
    $Database = Gdn::Database();
    $Construct = $Database->Structure();

    $Construct->Table('Discussion')
            ->Column('ModReviewUserID', 'int', NULL)
            ->Column('ModReviewDate', 'datetime', NULL)
            ->Set();

    $Construct->Table('Comment')
            ->Column('ModReviewUserID', 'int', NULL)
            ->Column('ModReviewDate', 'datetime', NULL)
            ->Set();
  }

  /**
   * Run the structure update on setup.
   */
  public function Setup() {
    $this->Structure();
  }

}
