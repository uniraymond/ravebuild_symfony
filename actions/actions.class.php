<?php

/**
 * client actions.
 *
 * @package    ravebuild
 * @subpackage client
 * @author     ravebuild
 */
require_once(sfConfig::get('sf_plugins_dir').'/sfGuardPlugin/modules/sfGuardAuth/lib/BasesfGuardAuthActions.class.php');

class clientActions extends RaveActions
{
  protected $sf_user;
  protected $sf_user_id;
  protected $sf_branch_id;
  protected $sf_owner_branch_ids;
  protected $sf_is_branch_owner;

  public function preExecute()
  {
    sfLoader::loadHelpers('Partial');
    $this->sf_user = $this->getUser();
    $this->sf_user_id = $this->sf_user->getGuardUser()->getId();
    $this->sf_branch_id = $this->sf_user->getUserBranch()->getId();
    $this->sf_is_branch_owner = $this->sf_user->isBranchOwner($this->sf_user_id);
    $this->sf_branch_owner_ids = null;
    if ($this->sf_is_branch_owner) {
      $this->sf_owner_branch_ids = BranchUsersPeer::getBranchOwnerBranchIDs($this->sf_user_id);
    }
    
    $this->error_message    = sfConfig::get('mod_client_upload_errormessage');
    $this->allowed_file_ext = sfConfig::get('mod_client_upload_fileext');
    $this->upload_redirect  = 'client/importclients';
    $this->upload_attribute = sha1('upload_client_csv_file');
  }
  /**
   * Executes index action
   *
   * @param sfRequest $request A request object
   **/
  public function executeIndex($request)
  {
    $user = $this->sf_user;
    $this->user_id = $this->sf_user_id;
    $branch_id = $this->sf_branch_id;
    $this->is_branch_owner = $this->sf_is_branch_owner;
    $this->owner_branch_ids = $this->sf_owner_branch_ids;

    $user->setAttribute('type', '');
    $user->setAttribute('keyword', '');
    $option = '';
    $this->keepstay = '';
    $user->setAttribute('user_client_status_closed',false);

    if($request->hasParameter('search'))
    {
      $search_criteria = $request->getParameter('search', array());
      $this->keepstay = $search_criteria['type'];
      if(array_key_exists($search_criteria['type'],$search_criteria))
      {
        $option = $search_criteria[$search_criteria['type']];
      }
      else
      {
        $option = $search_criteria['keyword'];
      }
      $user->setAttribute('type', $this->keepstay);
      $user->setAttribute('keyword', $option);
    }

    $this->search_value = $option;
    $staff_users = array();
    $staff_users[0] = 'Select a Staff';
    $sales_lists = array();
    $sales_lists[0] = 'Select a Sales';

    $this->client_ranks  =  clientRankPeer::getClientOpportunityListForSearch($branch_id);
    $admin_users = $this->getUser()->getBranchAdminUsers();
    foreach($admin_users as $admin_user)
    {
      $staff_users[$admin_user->getId()] = $admin_user->getProfile()->getFullname();
    }

    $sales_users = $this->getUser()->getBranchOfficeStaffUsers();
    foreach($sales_users as $sales_user)
    {
      $sales_lists[$sales_user->getId()] = $sales_user->getProfile()->getFullname();
    }

    $this->created_by = $staff_users;
    $this->sales = $sales_lists;
  }

  public function executeAjax($request)
  {

  }

  /**
   * Update client profile flag to display him in my client page at top
   * @param web_request $request
   */
  public function executeCheck($request)
  {
    $sf_user = $this->getUser();
    $change_by = $sf_user->getGuardUser()->getId();
    $sf_full_name = $sf_user->getProfile()->getFullname();
    $client_id = $request->getParameter('id');
    $client_user_id = ProfilePeer::getClientUserId($client_id);
    $flag = $request->getParameter('val_chck');
    $check_client = ($flag == 'true') ? 1 : 0;
    $priority_message = null;
    if($request->isMethod('post'))
    {
      $is_exist = clientPriorityViewPeer::isClientPriorityExist($client_id, $change_by);
      if($is_exist)
      {
        $con = Propel::getConnection();

        // select from...
        $c1 = new Criteria();
        $c1->add(clientPriorityViewPeer::CLIENT_ID, $client_id,  Criteria::EQUAL);
        $c1->add(clientPriorityViewPeer::PM_ID, $change_by,  Criteria::EQUAL);

        // update set
        $c2 = new Criteria();
        $c2->add(clientPriorityViewPeer::STATUS, $check_client);

        BasePeer::doUpdate($c1, $c2, $con);
      }
      else
      {
        $client_priority_view = new clientPriorityView();
        $client_priority_view->setClientId($client_id);
        $client_priority_view->setPmId($change_by);
        $client_priority_view->setStatus($check_client);
        $client_priority_view->save();
      }
      $priority_message = ($check_client == 1) ? sfConfig::get('mod_client_prioritymessage_subscribe') : sfConfig::get('mod_client_prioritymessage_unsubscribed');
      $modification_message = ($check_client == 1) ?  sfConfig::get('mod_client_priorityhistorymessage_subscribe')  :  sfConfig::get('mod_client_priorityhistorymessage_unsubscribe');

      $this->saveHistory($modification_message, $client_user_id);
    }
    return $this->renderText($priority_message);

  }

  public function executeShow($request)
  {
    // get login user details
    $user = $this->getUser();
    $user_id = $user->getGuardUser()->getId();
    $is_branch_owner = $user->isBranchOwner($user_id);

    $client_id = $request->getParameter('id');
    $client_group = sfConfig::get('app_user_group_user_client');
    $branch_id = $user->getUserBranch()->getId();

    if(!($user->hasHeadOfficeAdminAccess() || $user->hasHeadOfficeStaffAccess())){
      if($is_branch_owner)
      {
        $branch_ids = BranchUsersPeer::getBranchOwnerBranchIDs($user_id);
      }
      $branch_users = $this->getUser()->checkBranchUsers($is_branch_owner?$branch_ids:$branch_id, $client_id, $client_group);
      if(($client_id && !$branch_users) || (!$client_id)){
        if(!($user->hasHeadOfficeAdminAccess() || $user->hasHeadOfficeStaffAccess())):
        $this->redirect('dashboard/index');
        endif;
      }
    }
     
    // client profile details
    $profile_details = ProfilePeer::retrieveByPk($client_id);
    $client_user_id = $profile_details->getUserId();

    $this->profile_details = $profile_details;

    $clientC = new Criteria();
    $clientC->add(BranchUsersPeer::USER_ID, $client_user_id);
    $client_branch = BranchUsersPeer::doSelectOne($clientC);
    $this->client_branch_id = $client_branch->getBranchId();

    $c = new Criteria();
    $c->add(anotherContactPersonPeer::USER_ID, $this->profile_details->getUserId(), Criteria::EQUAL);
    $this->another_contact_list =  anotherContactPersonPeer::doSelect($c);

    // count all client event (current client)
    $this->count_all_event = pmProjectObjectsPeer::countClientDifferentStatusEvent($client_id);

    //get client project
    $c_project = new Criteria();
    $c_project->add(pmProjectsPeer::CLIENT_ID, $client_user_id);
    $this->clientproject = pmProjectsPeer::doSelectone($c_project);

    //get client_opportunity_record won
    $this->clientor_won = '';
    $c_won = new Criteria();
    $c_won->add(ClientOpportunityRecordPeer::USER_ID, $client_user_id);
    $c_won->add(ClientOpportunityRecordPeer::OPPORTUNITY_ID, 6);
    $this->clientor_won = ClientOpportunityRecordPeer::doSelectOne($c_won);

    // count uncompleted client event
    $this->count_undone_event = pmProjectObjectsPeer::countClientDifferentStatusEvent($client_id, pmProjectObjectsPeer::ORDERED);

    $this->client_projects = pmProjectsPeer::checkUserProjectsList($client_user_id);

    $clientOpportunityLogCriteria = new Criteria();
    $clientOpportunityLogCriteria->add(ClientOpportunityLogPeer::USER_ID, $this->profile_details->getUserId(), Criteria::EQUAL);
    $this->client_log = ClientOpportunityLogPeer::doSelect($clientOpportunityLogCriteria);

    //@end show client project

    $this->form = new ClientNoteForm($profile_details);
    $this->client_check = null;
    if(isset($branch_id))
    {
      $this->client_check = clientPriorityViewPeer::countPriorityClient($branch_id, $user_id);
      $this->branch_ranks = $this->getBranchRanks($branch_id);
      $this->branch_id = $branch_id;
    }

    $this->notes_form = new clientNotesForm();

    //get client opportunity records
    $this->oppDetails = array();
    $client_oppotunity_record = $this->getClientOpportunityRecords($client_user_id);
    if (!empty($client_oppotunity_record)) {
      foreach ($client_oppotunity_record as $cor) {
        $opp_name = clientRankPeer::getOpportunity($this->client_branch_id, $cor->getOpportunityId());

        if ($cor->getSubOpportunityId()) {
          $subOpp = SubOpportunityPeer::retrieveByPK($cor->getSubOpportunityId());
          $this->oppDetails[] = array(
              'client_opp_record_id'  =>  $cor->getId(),
              'opportunity_id' => $cor->getOpportunityId(),
              'opportunity_name' => $opp_name,
              'sub_opportunity_id' => $cor->getSubOpportunityId(),
              'sub_opportunity_name' => (!empty($subOpp) ? $subOpp->getName() : ''),
              'sub_opportunity_updated_at' => $cor->getUpdatedAt(),
              'sub_opportunity_updated_by' => $cor->getUpdatedById()
          );
        } else {
          $this->oppDetails[] = array(
              'client_opp_record_id'  =>  $cor->getId(),
              'opportunity_id' => $cor->getOpportunityId(),
              'opportunity_name' => $opp_name,
              'opportunity_updated_at' => $cor->getUpdatedAt(),
              'opportunity_updated_by' => $cor->getUpdatedById()
          );
        }
      }
    }

    $c = new Criteria();
    $c->add(clientNotesPeer::USER_ID, $profile_details->getUserId(), Criteria::EQUAL);
    $c->addDescendingOrderByColumn(clientNotesPeer::UPDATED_AT);
    $this->client_notes = clientNotesPeer::doSelect($c);
  }


  private function getClientOpportunityRecords ($client_id)
  {
    $c = new Criteria();
    $c->add(ClientOpportunityRecordPeer::USER_ID, $client_id);
    $c->addAscendingOrderByColumn(ClientOpportunityRecordPeer::OPPORTUNITY_ID);
    $c->addAscendingOrderByColumn(ClientOpportunityRecordPeer::SUB_OPPORTUNITY_ID);
    $cor =  ClientOpportunityRecordPeer::doSelect($c);
    return $cor;
  }

  public function executeRecvSort($request)
  {
    $contents = get_component('client', 'recvmessages_table');
    return $this->renderText($contents);
  }

  public function executeCreate($request)
  {
    $user = $this->getUser();
    $user_id = $user->getGuardUser()->getId();
    $branch_id = $user->getUserBranch()->getId();
    $is_branch_owner = $this->sf_is_branch_owner;
    $this->is_showlead = false;
    $this->marketing_options = array();

    if ($is_branch_owner) {
      $branch_ids = BranchUsersPeer::getBranchOwnerBranchIDs($user_id);
    }

    $this->cop_record_updated = '';

    $this->client_profile = '';
    $company = $user->getUserCompany();
    $this->company_settings = CompanySettingsPeer::getByCompanyId($company->getId());

    $ref = $this->genRandomString();
    $this->fname_alert = '';
    $this->form = new ClientQuickForm();
    $this->form->setDefault('other2', $ref);
    $this->client_leads = ClientLeadPeer::getClientLeadsList($branch_id);;

    $this->another_contact_form = new anotherContactPersonForm();

    $this->logindetails = array();
    $this->logindetails['username'] = '';
    $this->logindetails['password'] = '';
    $this->logindetails['confirm_password'] = '';
    $this->logindetails['budget'] = '';
    $this->logindetails['expected_close_date'] = '';

    $tempsale = array();
    // get sales persons (also branch office staff) list
    // get client branch_id
    if ($is_branch_owner && count($branch_ids) > 1) {
      $branch_id = 0;
    } else {
      $branch_service = new BranchService($branch_id, $user_id);
      $this->marketing_options = $branch_service->getMarketingOptionList();
      $this->is_showlead = $branch_service->isShowLead();
    }

    $sales = ProfilePeer::getBranchUsers($branch_id, sfGuardGroupPeer::BRANCH_OFFICE_STAFF);

    if ($sales) {
      foreach ($sales as $saleperson) {
        $tempsale[$saleperson->getUserId()] = $saleperson->getFullname();
      }
    }

    $this->sales_persons = $tempsale;
    $this->default_sales = '';
    $this->another_contact_list = '';
    $this->setTemplate('edit');
    $this->client_ranks =  clientRankPeer::getClientOpportunityList($branch_id);
    $this->default_rank = '';
    $this->default_lead = '';
    $this->client_id = 0;
    $this->sub_opportunity_exist = 0;
    
    $branch_service = new BranchService($branch_id, $user_id);
    $this->is_showlead = $branch_service->isShowLead();
    
    $this->marketing_options = $branch_service->getMarketingOptionList();
  }

  public function executeEdit($request) {
    $user = $this->sf_user;
    $user_id = $this->sf_user_id;
    $branch_id = $this->sf_branch_id;
    $is_branch_owner = $this->sf_is_branch_owner;

    $company = $user->getUserCompany();
    $this->company_settings = CompanySettingsPeer::getByCompanyId($company->getId());
    $branch_ids = array();
    $this->cop_record_updated = '';
    $this->marketing_options = null;

    if ($is_branch_owner) {
      $branch_ids = BranchUsersPeer::getBranchOwnerBranchIDs($user_id);
    }

    $client_id = $request->getParameter('id');
    $client_group = sfConfig::get('app_user_group_user_client');
    $branch_users = $user->checkBranchUsers($is_branch_owner?$branch_ids:$branch_id, $client_id, $client_group);

    $this->logindetails = array();
    $this->logindetails['username'] = '';
    $this->logindetails['password'] = '';
    $this->logindetails['confirm_password'] = '';
    $this->logindetails['budget'] = '';
    $this->logindetails['expected_close_date'] = '';
    $this->default_sales = '';
    $this->default_rank = '';
    $this->default_sub_rank = '';
    $this->default_lead = '';

    if (($client_id && !$branch_users) || (!$client_id)) {
      $this->redirect('dashboard/index');
    }
    // client profile details
    $client_profile = ProfilePeer::retrieveByPk($client_id);

    if ($client_profile) {
      $client_user_id = $client_profile->getUserId();

      $client_login = sfGuardUserPeer::retrieveByPK($client_user_id);
      if ($client_login) {
        $this->logindetails['username'] = $client_login->getUsername();
      }

      $budget_criteria = new Criteria();
      $budget_criteria->add(InquiryPeer::USER_ID, $client_user_id);
      $budget_detail = InquiryPeer::doSelectOne($budget_criteria);
      if ($budget_detail) {
        $this->logindetails['budget'] = $budget_detail->getBudget();
        $this->logindetails['expected_close_date'] = $budget_detail->getExpectedCloseDate();
      }
      $this->client_profile = $client_profile;

      //client opportunity record
      $client_opportunity_record = new Criteria();
      $client_opportunity_record->add(ClientOpportunityRecordPeer::USER_ID, $client_user_id);
      $client_opportunity_record->add(ClientOpportunityRecordPeer::OPPORTUNITY_ID, 6);
      $this->coprecord = ClientOpportunityRecordPeer::doSelectOne($client_opportunity_record);

      if(!empty($this->coprecord)) {
        $this->cop_record_updated = $this->coprecord->getUpdatedAt();
      }
    }

    $c = new Criteria();
    $c->add(anotherContactPersonPeer::USER_ID, $client_profile->getUserId(), Criteria::EQUAL);
    $this->another_contact_list =  anotherContactPersonPeer::doSelect($c);
    $this->another_contact_form = new anotherContactPersonForm();
    $this->form = new ClientQuickForm($client_profile);
    $this->client_login = sfGuardUserPeer::retrieveByPK($client_profile->getUserId());

    $tempsale = array();
    $tempsale[$user_id] = $this->getUser()->getProfile()->getFullname();
    if ($is_branch_owner) {
      if ($this->getRequestParameter('branch_id')) {
        $client_branch_id = $this->getRequestParameter('branch_id');
      } elseif (isset($client_user_id)) {
        $client_branch_id = BranchUsersPeer::getUserBranchId($client_user_id);
      } else {
        $client_branch_id = $branch_ids[0];
      }
    } elseif (isset($client_user_id)) {
      $client_branch_id = BranchUsersPeer::getUserBranchId($client_user_id);
    } else {
      $client_branch_id = $branch_id;
    }
    $this->client_leads = ClientLeadPeer::getClientLeadsList($client_branch_id);
    $sales = ProfilePeer::getBranchUsers($client_branch_id, sfGuardGroupPeer::BRANCH_OFFICE_STAFF);

    if ($sales) {
      foreach($sales as $salesid) {
        $tempsale[$salesid->getUserId()] = $salesid->getFullname();
      }
      $this->sales_persons = $tempsale;
    } else {
      $this->sales_persons = 'Select a Sales Person';
    }
    if (!empty($client_profile) && $client_profile->getSalesId()) {
      $this->default_sales = $client_profile->getSalesId();
    }

    if ($client_profile->getOther2() == '') {
      $ref = $this->genRandomString();
      $this->form->setDefault('other2', $ref);
    }

    $this->client_ranks =  clientRankPeer::getClientOpportunityList($client_branch_id);

    if(!empty($client_profile)) {
      //get opportunity and sub opportunity
      $this->default_rank = $client_profile->getSubOpportunity() ? $client_profile->getSubOpportunity() : $client_profile->getRank();
      $this->default_lead = $client_profile->getLead();
    }

    $this->sub_opportunity_exist = $client_profile->getSubOpportunity()?1:0;
    $branch_service = new BranchService($client_branch_id, $user_id);
    $this->marketing_options = $branch_service->getMarketingOptionList();
    $this->is_showlead = $branch_service->isShowLead();
  }

  /**
   * Save client profile content
   * @param web request $request
   */
  public function executeUpdate($request)
  {
    /**login user infomation**/
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_user_profile = $sf_guard_user->getProfile();
    $sf_user_id = $sf_guard_user->getId();
    $branch_id = $sf_user->getUserBranch()->getId();
    $company_id = $sf_user->getUserCompany()->getId();
    $this->marketing_options = '';
    $this->cop_record_updated = '';

    $client_user_id  = NULL;
    //    if($sf_ser->isBranchOwner($sf_user_id) && $sf_user->hasAttribute('branch_id'))
    if ($sf_user->isBranchOwner($sf_user_id)) {
      //        $client_user_id = $this->getRequestParameter('id');
      $client_profile_id = $this->getRequestParameter('id');
      if (!empty($client_profile_id)) {
        $client_user = ProfilePeer::retrieveByPK($client_profile_id);
        $client_user_id = $client_user->getUserId();
      }

      /*
       * available in case branch owner is handling more than branch
      * if the client is new that it need branch_id from url
      */
      if ($this->getRequestParameter('branch_id')) {
        $branch_id = $this->getRequestParameter('branch_id');
      } elseif ($client_user_id) {
        $client_branch = new Criteria();
        $client_branch->add(BranchUsersPeer::USER_ID, $client_user_id);
        $client_branch->setDistinct();
        $branchId = BranchUsersPeer::doSelect($client_branch);
        $branch_id = $branchId[0]->getBranchId();
      }

      $company_id = BranchPeer::getCompanyId($branch_id);
    }

    $parent = $request->getParameter('opportunity_parent_exist',0);
    $this->logindetails = array();
    $this->logindetails['username'] = '';
    $this->logindetails['password'] = '';
    $this->logindetails['confirm_password'] = '';
    $this->logindetails['budget'] = '';
    $this->logindetails['expected_close_date'] = '';

    $this->getSignedContractDate = '';

    $this->another_contact_list =  '';
    $this->another_contact_form = new anotherContactPersonForm();

    $this->client_leads = ClientLeadPeer::getClientLeadsList($branch_id);
    $login_details = $request->getParameter('logindetail');

    $branch_service = new BranchService($branch_id, $sf_user_id);
    $this->marketing_options = $branch_service->getMarketingOptionList();
    $this->is_showlead = $branch_service->isShowLead();
    $this->sub_opportunity_exist = null;

    if ($login_details) {
      $this->logindetails = $login_details;
      if ($this->logindetails['expected_close_date']) {
        $this->logindetails['expected_close_date'] = date('Y-m-d',strtotime($this->logindetails['expected_close_date'])).' '.date('H:i:s');
      }
    }

    $this->getSignedContractDate = $this->logindetails['signed_contract_date'];
     
    /*
     * get current branch branch office staff list (any one of these should be the sales person)
    */
    $tempsale = array();
    $tempsale[$sf_user_id] = $sf_user->getProfile()->getFullname();
    $sales = ProfilePeer::getBranchUsers($branch_id, sfGuardGroupPeer::BRANCH_OFFICE_STAFF);
    foreach ($sales as $salesid) {
      $tempsale[$salesid->getUserId()] = $salesid->getFullname();
    }
    $this->sales_persons = $tempsale;
    $this->default_sales = $sf_user_id;
    $client_profile = '';
    $this->client_profile = '';
    $client_id = $request->getParameter('id');
    if ($client_id) {
      $client_profile = ProfilePeer::retrieveByPK($client_id);
      $client_user_id = $client_profile->getUserId();
      $this->client_ranks =  clientRankPeer::getClientOpportunityList($branch_id);
      $this->default_rank = 0;
      $this->default_sub_rank = null;
      $this->default_lead = 0;

      if (!empty($client_profile)) {
        $this->default_rank = $client_profile->getRank() ? $client_profile->getRank() : 0;
        $this->default_sub_rank = $client_profile->getSubOpportunity() ? $client_profile->getSubOpportunity() : null;
      }

      $this->client_profile = $client_profile;
      if ($client_profile->getOther2() == '') {
        $ref = $this->genRandomString();
        $client_profile->setOther2($ref);
      }

      $this->form = new ClientQuickForm($client_profile);
      $client_login = sfGuardUserPeer::retrieveByPK($client_user_id);
      $this->client_login = $client_login;

      $c = new Criteria();
      $c->add(anotherContactPersonPeer::USER_ID, $client_user_id, Criteria::EQUAL);
      $this->another_contact_list =  anotherContactPersonPeer::doSelect($c);
    } else {
      $ref = $this->genRandomString();
      $this->form = new ClientQuickForm();
      $this->form->setDefault('other2', $ref);
      $this->client_ranks =  clientRankPeer::getClientOpportunityList($branch_id);
      $this->default_rank = 0;
      $this->default_sub_rank = null;
      $this->default_lead = 0;

      if(!empty($client_profile)) {
        $this->default_rank = $client_profile->getSubOpportunity()?$client_profile->getSubOpportunity():$client_profile->getRank();
        $this->default_lead = $client_profile->getLead();
      }
    }

    /*
     * save data to database
    */
    if ($request->isMethod('post')) {
      $form_data = $request->getParameter('profile');
      $prefered = null;
      if ($request->getParameter('preferedPhone')) {
        $prefered = $request->getParameter('preferedPhone');
      } elseif ($request->getParameter('preferedAfterHourPhone')) {
        $prefered = $request->getParameter('preferedAfterHourPhone');
      } elseif ($request->getParameter('preferedMobile')) {
        $prefered = $request->getParameter('preferedMobile');
      }

      $form_data['updated_at'] = date('Y-m-d H:i:s');
      $form_data['updated_by_id'] = $sf_user_id;
      $form_data['prefered_contact'] = $prefered;
      $form_data['user_id'] = $client_user_id;
      $sales_id = $form_data['sales_id'];

      if (!$sales_id) {
        $form_data['sales_id'] = $sf_user_id;
      } else {
        $form_data['sales_id'] = $sales_id;
      }

      if($parent) {
        $sub_opportunity = $form_data['rank'];
        $sub_opportunities = SubOpportunityPeer::retrieveByPK($sub_opportunity);
        $opportunities  = clientRankPeer::retrieveByPK($sub_opportunities->getOpportunityId());
        $form_data['rank'] = $opportunities->getRankId();
        $form_data['sub_opportunity'] = $sub_opportunity;

        if ($opportunities->getRankId() == 7) {
          $form_data['lead'] = ClientLeadPeer::getBranchLostId($branch_id);
        }
      } else {
        $form_data['sub_opportunity'] = null;
      }

      $client_rank = $form_data['rank']-1;

      $this->project = null;

      if($client_rank == 5) {
        $c = new Criteria();
        $c->add(pmProjectsPeer::CLIENT_ID, $client_user_id);
        $c->addDescendingOrderByColumn(pmProjectsPeer::CREATED_AT);
        $this->project = pmProjectsPeer::doSelectOne($c);
      }

      $this->form->bind($form_data);


      if ($this->form->isValid()) {
        $status = sfConfig::get('mod_client_opportunity_accountstatus');
        $form_data['account_status'] = accountStatusPeer::getStatusId($status[$client_rank])+1;

        if ($this->form->isNew()) {
          $form_data['created_by_id'] = $sf_user_id;
          $form_data['created_at'] = date('Y-m-d H:i:s');
          $form_data['updated_at'] = date('Y-m-d H:i:s');
          $form_data['updated_by_id'] = $sf_user_id;

          /*
           *  save client instance into sfguard
          */
          $sf_object = new sfGuardUser();
          $tools_obj = new myTools();

          /*
           * login infomation
          */
          if (!array_key_exists('username', $login_details) || !$login_details['username']) {
            $client_username = $tools_obj->RandomUsernameGenerator();
            $sf_object->setUsername($client_username);
          } else {
            $sf_object->setUsername($login_details['username']);
          }

          if (!array_key_exists('password', $login_details) || !$login_details['password']) {
            $sf_object->setPassword($tools_obj->randomPasswordGenerator());
          } else {
            $sf_object->setPassword($login_details['username']);
          }
          $sf_object->save();

          $sf_object->addGroupByName('client');
          $new_user_id = $sf_object->getId();
          $form_data['user_id'] = $new_user_id;
           
          $enquiry_details = new Inquiry();
          $enquiry_details->setUserId($new_user_id);

          if ($login_details['budget'] != '') {
            $enquiry_details->setBudget($login_details['budget']);
          }

          if ($login_details['expected_close_date']!='') {
            $enquiry_details->setExpectedCloseDate(date('Y-m-d',strtotime($login_details['expected_close_date'])));
          } elseif ($login_details['expected_close_date']=='') {
            $enquiry_details->setExpectedCloseDate(date('Y-m-d', strtotime(date('Y-m-01').' +6 month')));
          }
          $enquiry_details->save();
           
          /*
           *  save instance into branch users
          */
          $branch_object = new BranchUsers();
          $branch_object->addBranchUser($new_user_id, $branch_id);

          // set intance into company users
          $company_object = new CompanyUsers();
          $company_object->addCompanyUser($new_user_id, $company_id);
        } else {
          $enquiry_id = InquiryPeer::getEnquiryId($client_user_id);
          $enquiry_details = InquiryPeer::retrieveByPK($enquiry_id);

          if ($enquiry_details) {
            $enquiry_details->setBudget($login_details['budget']);
            $enquiry_details->setExpectedCloseDate(date('Y-m-d',strtotime($login_details['expected_close_date'])));
            $enquiry_details->save();
          } else {
            $enquiry_details = new Inquiry();
            $enquiry_details->setUserId($client_login->getId());

            if ($login_details['budget'] != '') {
              $enquiry_details->setBudget($login_details['budget']);
            }

            if ($login_details['expected_close_date']!='') {
              $enquiry_details->setExpectedCloseDate(date('Y-m-d',strtotime($login_details['expected_close_date'])));
            }
            $enquiry_details->save();
          }

          if ($client_login) {
            $client_login->setUsername($this->logindetails['username']);

            if ($this->logindetails['password']!='') {
              $client_login->setPassword($this->logindetails['password']);
            }
            $client_login->save();
            $new_user_id = $client_login->getId();
          }
        }


        if ($login_details['signed_contract_value'] != '') {
          $conn = Propel::getConnection();

          //                    need update only one record in the furture
          $cor = new Criteria();
          $cor->add(pmProjectsPeer::CLIENT_ID, $client_user_id);
          $cor->addDescendingOrderByColumn(pmProjectsPeer::CREATED_AT);

          $cor_new = new Criteria();
          $cor_new->add(pmProjectsPeer::ACTUAL_BUILD_COST, $login_details['signed_contract_value']);
          $cor_new->add(pmProjectsPeer::UPDATED_BY_ID, $sf_user_id);
          $cor_new->add(pmProjectsPeer::UPDATED_AT, date('Y-m-d H:i:s'));


          BasePeer::doUpdate($cor, $cor_new, $conn);
        }
        /*
         * save the form to profile
        */
        $profile = $this->form->save();
        $profile->setUserId($new_user_id ? $new_user_id : $client_user_id);
        $profile->save();

        $old_opportunity_id = 0;
        $old_sub_opportunity_id = 0;

        $old_opportunity_id = $this->default_rank;
        $old_sub_opportunity_id = $this->default_sub_rank;

        $new_opp_record = false;
        $c_opp_record = new Criteria();
        $c_opp_record->add(ClientOpportunityRecordPeer::USER_ID, $client_user_id);
        if ($old_sub_opportunity_id) {
          $c_opp_record->add(ClientOpportunityRecordPeer::SUB_OPPORTUNITY_ID, $old_sub_opportunity_id, Criteria::IN);
          $opportunity_records = ClientOpportunityRecordPeer::doSelect($c_opp_record);
        } elseif ($old_opportunity_id) {
          $c_opp_record->add(ClientOpportunityRecordPeer::OPPORTUNITY_ID, $old_opportunity_id, Criteria::IN);
          $opportunity_records = ClientOpportunityRecordPeer::doSelect($c_opp_record);
        } else {
          $opportunity_records = Null;
        }

        if (empty($opportunity_records)) {
          $new_opp_record = true;
        }

        $new_opportunity_id = $profile->getRank();
        $new_sub_opportunity_id = $profile->getSubOpportunity();

        if ($new_opp_record) {
          $client_opportunity_record = new ClientOpportunityRecord();
          $client_opportunity_record->setOpportunityId($new_opportunity_id);
          $client_opportunity_record->setSubOpportunityId($new_sub_opportunity_id);
          $client_opportunity_record->setUserId($profile->getUserId());
          $client_opportunity_record->setCreatedById($sf_user_id);
          $client_opportunity_record->setUpdatedById($sf_user_id);
          $client_opportunity_record->save();
        } else {
          $conn = Propel::getConnection();

          $client_opportunity_record_criteria = new Criteria();
          $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::USER_ID, $profile->getUserId());
          $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::OPPORTUNITY_ID, $new_opportunity_id);
          $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::SUB_OPPORTUNITY_ID, $new_sub_opportunity_id);

          $cor_new = new Criteria();
          if($new_opportunity_id == 6) {
            if (!empty($this->getSignedContractDate)) {
              $signed_updated_date = date('Y-m-d', strtotime($this->getSignedContractDate)).' '.date('H:i:s');
              $cor_new->add(ClientOpportunityRecordPeer::UPDATED_AT, $signed_updated_date);
            }
          } else {
            $cor_new->add(ClientOpportunityRecordPeer::UPDATED_AT, date('Y-m-d H:i:s'));
          }
          $cor_new->add(ClientOpportunityRecordPeer::UPDATED_BY_ID, $sf_user_id);

          BasePeer::doUpdate($client_opportunity_record_criteria, $cor_new, $conn);
        }

        if ($old_opportunity_id != $new_opportunity_id || $old_sub_opportunity_id != $new_sub_opportunity_id) {
          $client_opportunity_log = new ClientOpportunityLog();
          $client_opportunity_log->setUserId($profile->getUserId());
          $client_opportunity_log->setOpportunityId($new_opportunity_id);
          $client_opportunity_log->setSubOpportunityId($new_sub_opportunity_id);
          $client_opportunity_log->setCreatedById($sf_user_id);
          $client_opportunity_log->save();
        }

        /*
         *  delete record from another contact from current client
        */
        $c = new Criteria();
        $c->add(anotherContactPersonPeer::USER_ID ,$profile->getUserId());
        $another = anotherContactPersonPeer::doDelete($c);

        // add record from client
        $another_details = $request->getParameter('contact_person');
        $no_of_fields = 5;
        $count_person_list = count($another_details)/$no_of_fields;
        $j=$no_of_fields;
        for($i=0;$i<$count_person_list-1;$i++)
        {
          $fname = $another_details[$j]['fname'];
          $lname = $another_details[$j+1]['lname'];
          if($fname != '' || $lname != '')
          {
            $an_details = new anotherContactPerson();
            $an_details->setUserId($profile->getUserId());
            $an_details->setFname($another_details[$j++]['fname']);
            $an_details->setLname($another_details[$j++]['lname']);
            $an_details->setPhone($another_details[$j++]['phone']);
            $an_details->setEmail($another_details[$j++]['email']);
            $an_details->setMobile($another_details[$j++]['mobile']);
            $an_details->save();
          }
          else
          {
            $j=$j+$no_of_fields;
          }
        }

        if(!$request->getParameter('rdindex'))
        {
          $profile_id = $profile->getId();
          $profile_user_id = $profile->getUserId();
          // save client details in the activity logs table
          $modification_message = ($this->form->isNew()) ? 'Create Profile' : 'Update Profile';
          $this->saveHistory($modification_message, $profile_user_id);

          if($this->form->isNew())
          {
            $reminder = sfConfig::get('mod_client_messages_msg4');
            $sf_user->setFlash('notice', $reminder);
            $this->redirect('client/show?id='.$profile_id);
          }
          $client_info = sfConfig::get('mod_client_messages_msg2');
          $sf_user->setFlash('notice', $client_info);
          $this->redirect('client/show?id='.$profile_id);
        }
        $profile_id = $profile->getId();
        $this->redirect('inquiry/edit?id='.$profile_id);
      }
      if(isset($profile))
      {
        $this->sub_opportunity_exist = $profile->getSubOpportunity()?1:0;
      }
      $this->setTemplate('edit');
    }

  }

  /**
   * Add or Edit notes relate to current client profile
   * @param web_request $request
   */
  public function executeNotes($request)
  {
    $client_id = $request->getParameter('id');
    $client_profile = ProfilePeer::retrieveByPk($client_id);
    $client_user_id = $client_profile->getUserId();
    $this->form = new ClientNoteForm($client_profile);
    if($request->isMethod('post'))
    {
      $profile = $request->getParameter('profile');
      $this->form->bind($profile);
      if($this->form->isValid())
      {
        $profile = $this->form->save();
        $notes_message = sfConfig::get('mod_client_messages_msg5');
        $modification_message = 'Add Profile Notes';
        $this->saveHistory($modification_message, $client_user_id);
        $this->getUser()->setFlash('notice', $notes_message);
        $this->redirect('client/show?id='.$client_id);
      }
    }
  }

  public function executeClosed($request)
  {
    $option = '';
    $this->keepstay = '';
    $user = $this->getUser();
    $user->setAttribute('closed_type', '');
    $user->setAttribute('closed_keyword', '');

    if($request->hasParameter('search'))
    {
      $search_criteria = $request->getParameter('search', array());
      $this->keepstay = $search_criteria['type'];
      if(array_key_exists($search_criteria['type'],$search_criteria))
      {
        $option = $search_criteria[$search_criteria['type']];
      }
      else
      {
        $option = $search_criteria['keyword'];
      }
      $user->setAttribute('closed_type', $this->keepstay);
      $user->setAttribute('closed_keyword', $option);

    }
    $this->search_value = $option;

    $staff_users = array();
    $staff_users[0] = 'Select a Staff';
    $sales_lists = array();
    $sales_lists[0] = 'Select a Sales';

    $admin_users = $this->getUser()->getBranchAdminUsers();
    foreach($admin_users as $admin_user)
    {
      $staff_users[$admin_user->getId()] = $admin_user->getProfile()->getFullname();
    }

    $sales_users = $this->getUser()->getBranchOfficeStaffUsers();
    foreach($sales_users as $sales_user)
    {
      $sales_lists[$sales_user->getId()] = $sales_user->getProfile()->getFullname();
    }
    $this->created_by = $staff_users;
    $this->sales = $sales_lists;
  }

  public function executeClosedSearch($request)
  {
    $contents = get_component('client', 'closedclients_table');
    return $this->renderText($contents);
  }

  public function executeClientEventTable($request)
  {
    $contents = get_component('client', 'clientevent');
    return $this->renderText($contents);
  }

  public function executeClientAjax($request)
  {
    $contents = get_component('client', 'closedclients_table');
    return $this->renderText($contents);
  }

  public function executeDelete($request)
  {
    $user_profile = $this->getUser()->getGuardUser();
    $this->forward404Unless($sf_guard_user = sfGuardUserPeer::retrieveByPk($request->getParameter('id')));
    $sf_guard_user->delete();

    $log = new pmActivityLogs();
    $log->setModifications($profile->getId());
    $log->setAction('Delete Client');
    $log->setCreatedById($user_profile->getId());
    $log->setCreatedByName($user_profile->getProfile()->getFullname());
    $log->setComment('Client: '.$sf_guard_user->getProfile()->getFullname());
    $log->save();
    $this->redirect('register/index');
  }

  /**
   * Get uploaded files by client
   * @param Object $user_profile
   * @Return Propel Object
   */
  private function clientUploadedFiles($user_profile)
  {
    $profile_id = $user_profile->getProfile()->getId();
    $client = ProfilePeer::retrieveByPk($profile_id);
    $client_name = $client->getsfGuardUserRelatedByUserId()->getUsername();

    $c = new Criteria();
    $c->add(CompanyUsersPeer::USER_ID, $user_profile->getId());
    $company = CompanyUsersPeer::doSelectOne($c);
    if($company)
    {
      $company_name=$company->getCompany()->getName();
    }
    else
    {
      $company_name = '';
    }

    $c = new Criteria();
    $c->add(pmProjectObjectsPeer::TYPE, 'clientfile');
    $c->add(pmProjectObjectsPeer::MODULE, 'resources');
    $c->add(pmProjectObjectsPeer::VARCHAR_FIELD_1, $company_name.$client_name);
    $c->addAscendingOrderByColumn(pmProjectObjectsPeer::PJ_LOTNO);
    $r = pmProjectObjectsPeer::doSelect($c);
    $this->clientcompany = $company_name.$client_name;
    return $r;
  }

  public function executeAutofname($request)
  {
    $user = $this->getUser();
    $user_id = $user->getGuardUser()->getId();
    $branch_id = $user->getUserBranch()->getId();
    $fname = $this->getRequestParameter('profile[fname]');
    $this->fname = $fname;
    $lname = $this->getRequestParameter('profile[lname]');
    $this->lname = $lname;
    $email = $this->getRequestParameter('profile[email]');
    $this->email = $email;

    $c = new Criteria();
    $c->addJoin(sfGuardUserPeer::ID, sfGuardUserGroupPeer::USER_ID);
    $c->addJoin(sfGuardGroupPeer::ID, sfGuardUserGroupPeer::GROUP_ID);
    $c->addJoin(sfGuardUserPeer::ID, ProfilePeer::USER_ID);
    $c->addJoin(sfGuardUserPeer::ID, BranchUsersPeer::USER_ID);
    $c->addJoin(ProfilePeer::USER_ID, InquiryPeer::USER_ID,Criteria::LEFT_JOIN);
    $c->add(sfGuardGroupPeer::NAME, 'client');
    $c->add(BranchUsersPeer::BRANCH_ID, $branch_id);
    $c->add(ProfilePeer::SALES_ID, $user_id);

    if($fname) {
      $c->add(ProfilePeer::FNAME, $fname.'%', Criteria::LIKE);
    }
    if($lname) {
      $c->add(ProfilePeer::LNAME, $lname.'%', Criteria::LIKE);
    }
    if($email) {
      $c->add(ProfilePeer::EMAIL, $email.'%', Criteria::LIKE);
    }

    $this->autoclient = ProfilePeer::doSelect($c);
  }

  /**
   * get uploaded files by branch user using client file
   * @param object $client
   * @Return Propel Object
   */
  private function clientFiles($client)
  {
    $client_name = $client->getsfGuardUserRelatedByUserId()->getUsername();

    $c = new Criteria();
    $c->add(CompanyUsersPeer::USER_ID, $this->getUser()->getGuardUser()->getId());
    $company = CompanyUsersPeer::doSelectOne($c);
    $company_name = $company->getCompany()->getName();

    $c = new Criteria();
    $c->add(pmProjectObjectsPeer::TYPE, 'clientfile');
    $c->add(pmProjectObjectsPeer::MODULE, 'resources');
    $c->add(pmProjectObjectsPeer::VARCHAR_FIELD_1, $company_name.$client_name);
    $c->addAscendingOrderByColumn(pmProjectObjectsPeer::PJ_LOTNO);
    $r = pmProjectObjectsPeer::doSelect($c);
    $this->clientcompany = $company_name.$client_name;
    return $r;
  }

  /**
   * Render client uploaded files and file upload form
   * @param web_request $request
   */
  public function executeLoadfile($request)
  {
    $client_id = $request->getParameter('id');
    $client_profile = ProfilePeer::retrieveByPK($client_id);
    $this->profile_details = $client_profile;
    $sf_user = $this->getUser();
    $sf_user_id = $sf_user->getGuardUser()->getId();
    $sf_guard_user = $sf_user->getGuardUser();
    $module = pmProjectObjectsPeer::RESOURCES;
    $filetype = pmProjectObjectsPeer::CLIENTFILES;
    $client_group = sfConfig::get('app_user_group_user_client');
     
    if(!($sf_user->hasHeadOfficeAdminAccess() || $sf_user->hasHeadOfficeStaffAccess()))
    {
      $branch_id = $sf_user->getUserBranch()->getId();
      $is_branch_owner = $sf_user->isBranchOwner($sf_user_id);
      if($is_branch_owner)
      {
        $branch_ids = BranchUsersPeer::getBranchOwnerBranchIDs($sf_user_id);
      }
      $branch_users = $sf_user->checkBranchUsers($is_branch_owner?$branch_ids:$branch_id, $client_id, $client_group);
      if(($client_id && !$branch_users) || (!$client_id))
      {
        $this->redirect('dashboard/index');
      }
    }

    $client_name = $client_profile->getsfGuardUserRelatedByUserId()->getUsername();

    $c = new Criteria();
    $c->add(CompanyUsersPeer::USER_ID, $this->getUser()->getGuardUser()->getId());
    $company = CompanyUsersPeer::doSelectOne($c);
    $company_name =$company?$company->getCompany()->getName():'';

    $this->clientcompany = $company_name.$client_name;

    $this->form = new ClientFileForm();

    $client_user_id = $client_profile->getUserId();
    $this->client_projects = pmProjectsPeer::getClientProjectsArrayList($client_user_id);
    if($request->isMethod('post'))
    {
      $form_data = $request->getParameter('pm_project_objects');
      if($form_data['name'] == sfConfig::get('app_client_details_otheragreement')) {
        $form_data['name'] = $form_data['name'].'-'.$form_data['text_field_2'];
      }
      else {
        $form_data['name'] = $form_data['name'];
      }
      $form_data['module'] = $module;
      $form_data['type'] = $filetype;
      //$form_data['branch_id'] = $branch_id; // no need
      $form_data['created_by_id'] = $sf_guard_user->getId();
      $form_data['created_by_name'] = $sf_guard_user->getUsername();
      $form_data['integer_field_1'] = $client_user_id;
      $form_data['text_field_1'] = $request->getParameter('nfn');
      $tool = new myTools();
      if($form_data['project_id'])
      {
        $folder_name = $tool->folderName($form_data['project_id'], 'project');
      }
      else
      {
        $folder_name = $tool->folderName($form_data['pj_lotno'], 'pj_lotno');
      }

      $s3_service = new AmazonS3Service();
      $s3_service->copyObject($request->getParameter('nfn'), $folder_name.'/'.$request->getParameter('nfn'));
      $s3_service->deleteObject($request->getParameter('nfn'));

      $this->form->bind($form_data, array());

      if ($this->form->isValid())
      {
        $file = $this->form->getValue('varchar_field_2');
        if($file)
        {
          $pm_project_objects = $this->form->save();

          $file_name = $pm_project_objects->getName();
          $file_message = sfConfig::get('mod_client_messages_msg6');
          $new_message = str_replace('new text', "'{$pm_project_objects->getPjLotno()}' - '{$file_name}'" , $file_message);
          $sf_user->setFlash('notice', $new_message);
          $modification_message = 'Upload Client File';
          $this->saveHistory($modification_message, $client_user_id);

          $log = new pmActivityLogs();
          $log->setModifications($pm_project_objects->getId());
          $log->setAction('New File');
          $log->setCreatedById($sf_guard_user->getId());
          $log->setCreatedByName($sf_guard_user->getProfile()->getFullname());
          $log->setComment('File: '.$file_name);
          $log->save();
        }
        $this->redirect('client/loadfile?id='.$client_id);
      }
      $this->setTemplate('loadfile');
    }
  }

  /**
   * Delete uploaded files by client
   * @param web_request $request
   */
  public function executeFileDelete($request)
  {
    $file_id = $request->getParameter('file_id');
    $client_id = $request->getParameter('id');
    $client_profile = ProfilePeer::retrieveByPK($client_id);
    $client_user_id = $client_profile->getUserId();
    $file_details = pmProjectObjectsPeer::retrieveByPK($file_id);
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $project_id = $request->getParameter('project_id');

    $file_name = $file_details->getName();
    $file_details->setArchived(1);
    $file_details->setUpdatedById($sf_guard_user->getId());
    $file_details->setUpdatedAt(date('d-m-Y H:i:s'));
    $file_details->save();

    $sf_user->setFlash('delete', 'The "'.$file_details->getPjLotno().' - '.$file_details->getName().'" has been Deleted successfully.');

    $modification_message = 'Delete File '.$file_name;
    $this->saveHistory($modification_message, $client_user_id);

    $log = new pmActivityLogs();
    $log->setModifications($file_id);
    $log->setAction('Delete file');
    $log->setCreatedById($sf_guard_user->getId());
    $log->setCreatedByName($sf_guard_user->getProfile()->getFullname());
    $log->setComment('File: '.$file_name);
    $log->save();
    if($project_id) {
      $this->redirect('client/clientloadfile?project_id='.$project_id);
    }
    $this->redirect('client/loadfile?id='.$client_id);
  }

  public function executeList($request)
  {
    $client_id = $request->getParameter('id');
    $this->client_profile = ProfilePeer::retrieveByPk($client_id);

    $c = new Criteria();
    $c->addJoin(pmProjectUsersPeer::PROJECT_ID, pmProjectsPeer::ID);
    $c->add(pmProjectUsersPeer::USER_ID, $this->client_profile->getUserId());
    $this->client_projects = pmProjectsPeer::doSelect($c);
  }

  /**
   * render particular client inquiry related content
   * @param web_request $request
   */
  public function executeInquiryshow($request)
  {
    $sf_user         = $this->getUser();
    $sf_user_id      = $sf_user->getGuardUser()->getId();
    $client_id       = $request->getParameter('id');
    $client_group    = sfConfig::get('app_user_group_user_client');
    $branch_id       = $sf_user->getUserBranch()->getId();
    $inq_spec_lables = array();


    if(!($sf_user->hasHeadOfficeAdminAccess() || $sf_user->hasHeadOfficeStaffAccess()))
    {
      $is_branch_owner = $sf_user->isBranchOwner($sf_user_id);
      if($is_branch_owner)
      {
        $branch_ids = BranchUsersPeer::getBranchOwnerBranchIDs($sf_user_id);
      }
       
      /** Check client exists in login user branch otherwise redirect to dashboard **/
      $branch_users = $sf_user->checkBranchUsers($is_branch_owner?$branch_ids:$branch_id, $client_id, $client_group);
      if(($client_id && !$branch_users) || (!$client_id))
      {
        $this->redirect('dashboard/index');
      }
    }

    $inquiry_spec = BranchEnquirySpecificationSettingsPeer::getSpecificationByBranchId($branch_id);
    if(!$inquiry_spec) {
      $company       = $sf_user->getUserCompany();
      $comp_settings = CompanySettingsPeer::getByCompanyId($company->getId());
      $inquiry_spec  = CompanyEnquirySpecificationSettingsPeer::getDefaultSpecifications();

      if($comp_settings && $comp_settings->getCustomSpecification() != 0) {
        $inquiry_spec = CompanyEnquirySpecificationSettingsPeer::getSpecificationByCompanyId($company->getId());
      }
    }

    foreach($inquiry_spec as $spec) {
      $inq_spec_lables[$spec->getSpecificationName()] = $spec->getCompanySpecificationName();
    }

    $profile_details = ProfilePeer::retrieveByPK($client_id);
    $this->client_details = $profile_details;
    $client_user_id = $profile_details->getUserId();
    $c = new Criteria();
    $c->add(InquiryPeer::USER_ID, $client_user_id);
    $this->inquiry = InquiryPeer::doSelectOne($c);
    $this->inquiry_labels = $inq_spec_lables;
  }

  /**
   * Render client event form
   * @param web_request $request
   */
  public function executeEditEvent($request)
  {
     
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_guard_userid = $sf_guard_user->getId();
    $client_id = $request->getParameter('id');
    $client_profile = ProfilePeer::retrieveByPK($client_id);

    $event_id = $request->getParameter('event_id');
    $event_details = '';

    if($event_id) {
      $event_details = pmProjectObjectsPeer::retrieveByPK($event_id);
      $this->form = new ClientEventForm($event_details);
    }
    else {
      $this->form = new ClientEventForm();
    }

    $client_branch_id = BranchUsersPeer::getUserBranchId($client_profile->getUserId());

    $staff_detail = $this->getUser();

    $sale_person = array();
    $sale_person[$sf_guard_userid] = $staff_detail->getProfile()->getFullname();

    $staff_persons = ProfilePeer::getBranchUsers($client_branch_id, sfGuardGroupPeer::BRANCH_OFFICE_STAFF);

    foreach($staff_persons as $staff_person)
    {
      $sale_person[$staff_person->getUserId()] = $staff_person->getFname().' '.$staff_person->getLname();
    }

    $this->staff_id = $sale_person;
    $this->default_staff = '';

    if($event_details)
    {
      if($event_details->getContractId())
      {
        $this->default_staff = $event_details->getContractId();
      }
    }
    else
    {
      $this->default_staff = $sf_guard_userid;
    }

  }

  /**
   * Save client event
   * @param web request $request
   */
  public function executeUpdateEvent($request)
  {
    $sf_user = $this->getUser();
    $sf_user_id = $sf_user->getGuardUser()->getId();
    $client_id = $request->getParameter('id');
    $client_profile = ProfilePeer::retrieveByPK($client_id);
    $sfguard_user_profile = $sf_user->getProfile();
    $sfguard_fullname = $sfguard_user_profile->getFullname();
    $sfguard_email = $sfguard_user_profile->getEmail();
    $start_hour = $request->getParameter('start_hour');
    $start_min = $request->getParameter('start_min');
    $time_mode = $request->getParameter('start_mn');
    $client_branch_id = BranchUsersPeer::getUserBranchId($client_profile->getUserId());

    $hour = sfConfig::get('mod_client_outlook_shour');
    $sc_hour = sfConfig::get('mod_client_secondhalf_shhour');

    if($request->isMethod('post'))
    {
      $event_id = $this->getRequestParameter('event_id');
      $event_details = '';
      if($event_id)
      {
        $event_details = pmProjectObjectsPeer::retrieveByPK($event_id);
        $this->form = new ClientEventForm($event_details);
      }
      else
      {
        $this->form = new ClientEventForm();
      }

      $event_data = $request->getParameter('pm_project_objects');
      if ($time_mode == 'AM')
      {
        $start_hour_value = ($start_hour == 11)?'00':$hour[$start_hour];
        $date_field_1 = $event_data['date_field_1'].' '.$start_hour_value.':'.$start_min.':00';
      }
      elseif($time_mode == 'PM')
      {
        $start_hour_value = ($start_hour == 11)?$hour[$start_hour]:$sc_hour[$start_hour];
        $date_field_1 = $event_data['date_field_1'] .' '.$start_hour_value.':'.$start_min.':00';
      }

      $event_data['module'] = 'client event';
      $event_data['tree_left'] = $client_branch_id;
      $event_data['tree_right'] = $sf_user_id;
      $event_data['integer_field_2'] = $client_id;
      $event_data['date_field_1'] = $date_field_1;
      $event_data['contract_id'] = $request->getParameter('contract_id');
      $event_data['created_by_id'] = $sf_user_id;
      $event_data['updated_by_id'] = $sf_user_id;

      $this->form->bind($event_data);
      if($this->form->isValid())
      {
        $client_event = $this->form->save();
        $client_event->setParentId($client_event->getId());
        $client_event->setUpdatedAt($date_field_1);
        $client_event->save();

        // create instance of event class and add client event in event table
        $event = '';
        $c = new Criteria();
        $c->add(EventPeer::CEVENT_ID, $event_id);
        $event = EventPeer::doSelectOne($c);
        if(!$event) {
          $event = new Event();
        }
        $event_start_date = $client_event->getDateField1();
        $client_full_name = $client_profile->getFname().' '.$client_profile->getLname();
        $event->setUserId($client_profile->getUserId());
        $event->setSubject($client_full_name.': '.$client_event->getName().': '.$client_event->getBody());
        $event->setBody($client_event->getName().': '.$client_event->getBody());
        $event->setEventType('client event');
        $event->setStartTime($date_field_1);
        $event->setEndTime($date_field_1);
        $event->setCeventId($client_event->getId());
        $event->setCalendarId(calendarsPeer::getCalendarIdByUserId($sf_user_id));
        $event->save();

        $ical = $this->getICalData($event);
        $event->setUri($ical->filename);
        $event->setIcsData($ical->createCalendar());
        $event->setCreatedById($sf_user_id);
        $event->save();

        $events = $event;

        if($time_mode == 'PM') {
          $hour = sfConfig::get('mod_client_outlook_shour');
        }
        else {
          $hour = sfConfig::get('mod_client_outlook_shour');
        }

        $start_time = strtotime($event_start_date);
        $end_time = strtotime($event_start_date);
        $hour_start = $hour[$start_hour];//set default 9:00am
        $minute_start = $start_min;
        $end_hr = $start_min;

        // star time
        $start = array( 'year'=>date('Y', $start_time), 'month'=>date('m', $start_time),
            'day'=>date('d', $start_time), 'hour'=>$hour_start, 'min'=>$minute_start, 'sec'=>date('s', $start_time));

        $outlook_start = date('d-m-Y', $start_time);
        $metting_start_time = $hour_start.':'.$minute_start.':'.'00';
        $mode = ($time_mode == 'PM') ? ' PM' : ' AM';
        $metting_start_time = $metting_start_time.$mode;

        sfConfig::set('sf_web_debug', false);
        $description = get_partial('message_data', array());
        $sender = str_replace('sender', "{$client_full_name}" , $description);
        $topic = str_replace('topic', "{$client_event->getName()}" , $sender);
        $time = str_replace('start', "{$outlook_start}" , $topic);
        $s_t = str_replace('s_t', "{$metting_start_time}" , $time);
        $place = str_replace('place', "{$client_event->getVarcharField1()}" , $s_t);
        $subject = str_replace('subject', "{$client_event->getBody()}" , $place);

        $involved_user = $client_event->getContractId();
        $c = new Criteria();
        $c->add(ProfilePeer::USER_ID, $involved_user);
        $involved_user_profile = ProfilePeer::doSelectOne($c);
        $organizer = $involved_user_profile->getEmail();

        if($time_mode == 'PM') {
          $outlook_hour = sfConfig::get('mod_client_soutlook_dhour');
        }
        else {
          $outlook_hour = sfConfig::get('mod_client_secoutlook_sechour');
        }
        $time_start = array( 'year'=>date('Y', $start_time), 'month'=>date('m', $start_time),
            'day'=>date('d', $start_time), 'hour'=>$outlook_hour[$hour_start], 'min'=>$minute_start, 'sec'=>date('s', $start_time));

        $v_event = new vevent();
        $v_event->setProperty("organizer",'MAILTO:'.$organizer);
        //$v_event->setProperty("recurrence-id", $start);
        $v_event->setProperty('uid', md5($events->getId()));
        $v_event->setProperty('dtstamp', $events->getCreatedAt());
        $v_event->setProperty('dtstart', $time_start);
        $v_event->setProperty('location', $client_event->getVarcharField1());
        $v_event->setProperty('dtend', $time_start);
        $v_event->setProperty('summary', $events->getSubject());
        $v_event->setProperty('description', $subject);
        $v_event->setProperty("status", "CONFIRMED");
        $v_timezone = new vtimezone();
        $v_timezone->setProperty("tzid", "Pacific/Auckland");
        $v_event->setComponent($v_timezone);
        $cal_events[] = $v_event;
        $v_alarm = new valarm();
        $v_alarm->setProperty('trigger', 'PT15M');
        $v_alarm->setProperty('action', 'display');
        $v_alarm->setProperty('Description', "Reminder: ".  $subject);
        $v_event->setComponent($v_alarm);

        $calendar_events = $cal_events;
        $config = array( 'unique_id' => 'ravebuild.com' );
        $v_calendar = new vcalendar();
        $v_calendar->setProperty("method", "REQUEST");

        $mail_data = $this->setIcalEvents($v_calendar, $calendar_events);
        $all_events = $v_calendar->createCalendar();

        $send_mail = new mailSend();
        $send_mail->sendInvitationToUser($organizer, null, $all_events);

        $event_type = ($event_id) ? 'updated' : 'added';
        $modification_message = ($event_id) ? 'Update Client Event' : 'Add Client Event';
        $client_user_id = $client_profile->getUserId();
        $this->saveHistory($modification_message, $client_user_id);
        $sf_user->setFlash('notice', 'The Event "'.$client_event->getBody().'" has been '.$event_type.' successfully.');
        $this->redirect($request->getReferer());
      }
    }
  }

  public function executeUpdatEvent($request)
  {
    $client_id = $request->getParameter('id');
    $event_id = $this->getRequestParameter('event_id');
    $new_status = $this->getRequestParameter('status');

    $event_details = pmProjectObjectsPeer::retrieveByPK($event_id);

   	if($event_id)
   	{
   	  $c = new Criteria();
   	  $c->add(pmProjectObjectsPeer::MODULE, 'client event');
   	  $c->add(pmProjectObjectsPeer::INTEGER_FIELD_2, $client_id);
   	  $c->add(pmProjectObjectsPeer::ID, $event_id);
   	  $c->add(pmProjectObjectsPeer::INTEGER_FIELD_1, $new_status);
   	  pmProjectObjectsPeer::doUpdate($c);
   	}

   	$followup_status = sfConfig::get('app_client_followupstatus');
   	$old_status = $followup_status[$event_details->getIntegerField1()];
   	$new_status = $followup_status[$new_status];
   	$followup_name   = $event_details->getBody();

   	$client_user_id = ProfilePeer::getClientUserId($client_id);

   	$action = 'Followup: '.$followup_name.' status change from '.$old_status.' to '.$new_status;
   	$this->saveHistory($action, $client_user_id);

   	if($request->isXmlHttpRequest())
   	{
   	  return $this->renderComponent('client', 'client_log', array());
   	}

   	return sfView::NONE;
  }

  /**
   * Delete Client Event
   * @param web_request $request
   */
  public function executeDeleteEvent($request)
  {
    $this->forward404Unless($event_details = pmProjectObjectsPeer::retrieveByPk($request->getParameter('event_id')));
    $sf_user = $this->getUser();
    $client_id = $request->getParameter('id');
    $client_profile = ProfilePeer::retrieveByPk($client_id);
    $client_user_id = $client_profile->getUserId();
    $this->clientname = $client_profile;

    $client_event = $event_details->getBody();
    $event_details->delete();

    $modification_message = 'Delete Client Event '.$client_event;
    $this->saveHistory($modification_message, $client_user_id);

    $sf_user->setFlash('notice', 'The Event "'.$client_event.'" has been deleted successfully.');
    $this->redirect('client/show?id='.$client_id);
  }

  public function executeMessage($request)
  {
    $client_profile = ProfilePeer::retrieveByPk($request->getParameter('id'));
    $this->client_profile = $client_profile;
  }

  public function executeMessageSort($request)
  {

  }

  public function executeReceMessage($request)
  {
    $contents = get_component('client', 'recvmessages_table');
    return $this->renderText($contents);
  }

  public function executeSentMessage($request)
  {
    $contents = get_component('client', 'clientsmessages_table');
    return $this->renderText($contents);
  }

  /**
   * Save client message
   * @param web request $request
   */
  public function executeCreateMessage($request)
  {
    $client_id = $request->getParameter('id');
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_user_id = $sf_guard_user->getId();
    $sf_user_name = $sf_guard_user->getUsername();
    $branch_id = $sf_user->getUserBranch()->getId();

    $client_profile = ProfilePeer::retrieveByPk($client_id);
    $this->client_profile = $client_profile;
    $client_user_id = $client_profile->getUserId();
    $this->form = new ClientMessagesForm();
    if($request->isMethod('post'))
    {
      $send_mail = $this->getRequestParameter('send_mail');
      $message_data = $request->getParameter('messages');
      $message_data['sender'] = $sf_user_name;
      $this->form->bind($message_data);
      if($this->form->isValid())
      {
        $message = $this->form->save();

        $modification_message = 'Send Message to client using client overview';
        $this->saveHistory($modification_message, $client_user_id);

        $message_receiver = new MessageReceivers();
        $message_receiver->setUserId($client_user_id);
        $message_receiver->setSeen(false);
        $message_receiver->setMessageId($message->getId());
        $message_receiver->save();
        if($send_mail) {
          $this->sendMailToClient($sf_guard_user, $message, $client_profile);
        }
        $this->redirect('client/message?id='.$client_id);
      }
    }
  }

  /**
   * send mail to client
   * @param Object $sf_guard_user
   * @param Object $message
   * @param Object $client_profile
   */
  private function sendMailToClient($sf_guard_user, $message, $client_profile)
  {
    $profile = $this->getUser()->getGuardUser()->getProfile();
    $set_action = 'Send mail to client';
    $client_full_name = $client_profile->getFname().' '.$client_profile->getLname();
    $client_mail = $client_profile->getEmail();

    $tools = new myTools();
    // if user tick checkbox to send the mail
    if($client_mail && $tools->isValidEmail($client_mail))
    {
      $category = $message->getCategory();
      $msgs_category = new Messages();
      $category_change_to_title = $msgs_category->messageCategory($category);

      sfConfig::set('sf_web_debug', false);
      $content = get_partial('mail_data', array('profile'=>$profile));
      $change_to = str_replace('changeto', "{$client_full_name}" , $content);
      $change_from = str_replace('changefrom', $sf_guard_user->getProfile()->getFullname() , $change_to);
      $change_subject = str_replace('changesbjct', $message->getSubject() , $change_from);
      $change_cat = str_replace('changecat', $category_change_to_title, $change_subject);
      $change_body = str_replace('changebody', $message->getBody() , $change_cat);
      $mailBody = $change_body;

      // send mail to client
      $mailclass_obj = new mailSend();
      $mailclass_obj->sendMailToUser($mailBody, $client_mail, $set_action);
    }
  }

  public function executeEditComment($request)
  {
    $comment_id = $this->getRequestParameter('comment_id');
    $comment_details = CommentsPeer::retrieveByPK($comment_id);
    $this->form = new CommentsForm($comment_details);
  }

  /**
   * display client message details
   * @param web request $request
   */
  public function executeShowMessage($request)
  {
    $sf_user = $this->getuser();
    $sf_guard_user = $sf_user->getGuardUser();

    $sf_user_id = $sf_guard_user->getId();
    $branch_id = $sf_user->getUserBranch()->getId();
    $client_id = $request->getParameter('id');
    $client_details =ProfilePeer::retrieveByPK($client_id);
    $message_id = $request->getParameter('message_id');
    $update_comment = $request->getParameter('comment_update');
    $comment_id = $request->getParameter('comment_id');
    $message_details = MessagesPeer::retrieveByPK($message_id);
    $this->message_details = $message_details;
    $message_sender = $message_details->getSender();
    $this->sender = ProfilePeer::getMessageSenderName($message_sender);
    $this->comments = CommentsPeer::getMessageAllComments($message_id);
    $this->form = new CommentsForm();
    if($comment_id)
    {
      $comment_details = CommentsPeer::retrieveByPK($comment_id);
      $this->form = new CommentsForm($comment_details);
    }
    if($update_comment)
    {
      $update_status = new Messages();
      $update_status->setMessageCommentsStatus($message_id, $sf_user_id);
    }

    if($request->isMethod('post'))
    {
      $form_data = $request->getParameter('comments');
      $form_data['sender'] = $sf_guard_user->getUsername();
      $form_data['message_id'] = $message_id;
      $this->form->bind($form_data);
      if($this->form->isValid())
      {
        $comment = $this->form->save();
        $comment_sender = $comment->getSender();
        $this->updateMessageStatusOnComment($comment_sender, $message_id);
        $this->redirect('client/showMessage?id='.$client_id.'&message_id='.$message_id);
      }
    }
    $this->setTemplate('showMessage');
  }

  /**
   * Display event details
   * @param web request $request
   */
  public function executeShowEvent($request)
  {
    $client_id = $request->getParameter('id');
    $event_id = $request->getParameter('event_id');
    $client_profile = ProfilePeer::retrieveByPk($client_id);
    $this->client_profile = $client_profile;
    $this->clienttodoshow = pmProjectObjectsPeer::retrieveByPk($event_id);
  }

  /**
   * Delete comment
   * @param web request $request
   */
  public function executeDeleteComment($request)
  {
    $client_id = $request->getParameter('id');
    $message_id = $request->getParameter('message_id');
    $comment_id = $request->getParameter('comment_id');
    $comments_details = CommentsPeer::retrieveByPK($comment_id);
    $comments_details->delete();
    $client_profile = ProfilePeer::retrieveByPK($client_profile);
    $client_user_id = $client_profile->getUserId();
    // save details into log table
    $modification_message = 'Delete Comment';
    $this->saveHistory($modification_message, $client_user_id);
    $this->redirect('client/showMessage?id='.$client_id.'&message_id='.$message_id);
  }

  public function executeSalesPager($request)
  {
    $contents = get_component('client', 'myclients_table');
    return $this->renderText($contents);
  }

  public function executeNsalesPager($request)
  {
    $contents = get_component('client', 'otherclients_table');
    return $this->renderText($contents);
  }

  public function executeSearch($request)
  {
    $this->renderComponent('client', 'autocomplete');
    return sfView::NONE;
  }

  public function executeClosedautosearch($request)
  {
    $this->renderComponent('client', 'closedclientautocomplete');
    return sfView::NONE;
  }

  /**
   * Render build create form
   * @param web request $request
   */
  public function executeBuildcreate($request)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_user_id = $sf_guard_user->getId();
    $sf_user_fullname = $sf_guard_user->getProfile()->getFullname();
    $branch_id = $sf_user->getUserBranch()->getId();
    $is_branch_owner = $sf_user->isBranchOwner($sf_user_id);
    if($is_branch_owner)
    {
      $branch_ids = BranchUsersPeer::getBranchOwnerBranchIDs($sf_user_id);
    }
    $client_id = $request->getParameter('id');

    $client_details = ProfilePeer::retrieveByPK($client_id);
    $client_user_id = $client_details->getUserId();

    $client_group = sfConfig::get('app_user_group_user_client');
    $branch_users = $sf_user->checkBranchUsers(($is_branch_owner)?$branch_ids:$branch_id, $client_id, $client_group);

    if(($client_id && !$branch_users) || (!$client_id))
    {
      $this->redirect('dashboard/index');
    }
    $temp[$sf_user_id] = $sf_user_fullname;

    $client_branch_id = BranchUsersPeer::getUserBranchId($client_user_id);

    $branch_groups = array(sfGuardGroupPeer::BRANCH_OFFICE_STAFF, sfGuardGroupPeer::BRANCH_OFFICE_ADMIN, sfGuardGroupPeer::BRANCH_OWNER);
    // var_dump($branch_groups);
    $leaders = ProfilePeer::getBranchUsers($client_branch_id, $branch_groups);

    $this->leader = $leaders;
    foreach($leaders as $leader)
    {
      $temp[$leader->getUserId()] = $leader->getFullname();
    }
    $this->leader_id = $temp;
    $this->defult_leader = 0;
    $this->client_id = 0;

    $this->form = new pmProjectsForm();
    $this->setTemplate('build');
  }

  /**
   * Add client into new build
   * @param web request $request
   */
  public function executeBuild($request)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_user_id = $sf_guard_user->getId();
    $sf_user_profile = $sf_guard_user->getProfile();
    $sf_user_fullname = $sf_user_profile->getFullname();
    $sf_user_name = $sf_guard_user->getUsername();
    $branch_id = $sf_user->getUserBranch()->getId();

    $client_id = $request->getParameter('id');

    $client_details = ProfilePeer::retrieveByPK($client_id);
    $client_user_id = $client_details->getUserId();

    //    if ($client_user_id) {
    //        $branch_id = ProfilePeer::getClientBranch($client_user_id)->getBranchId();
    //    }
    //
    $temp[$sf_user_id] = $sf_user_fullname;
    $leaders = ProfilePeer::getBranchUsers($branch_id, sfGuardGroupPeer::BRANCH_OFFICE_STAFF);
    $this->leader = $leaders;
    foreach($leaders as $leader)
    {
      $temp[$leader->getUserId()] = $leader->getFullname();
    }
    $this->leader_id = $temp;
    $this->defult_leader = 0;
    $this->client_id = 0;


    $this->form = new pmProjectsForm();
    if($request->isMethod('post'))
    {
      $form_data = $request->getParameter('pm_projects');
      $client_branch_id = BranchUsersPeer::getUserBranchId($client_user_id);
      $form_data['created_by_id'] = $sf_user_id;
      $form_data['created_by_name'] = $sf_user_name;
      $form_data['created_by_email'] = $sf_user_profile->getEmail();
      $form_data['branch_id'] = $client_branch_id;
      $form_data['client_id'] = $client_user_id;
      $leader_id = $this->getRequestParameter('leader_id');
      if($leader_id)
      {
        $form_data['leader_id'] = $leader_id;
        $project_manager = sfGuardUserPeer::retrieveByPk($leader_id);
        $manager_profile = $project_manager->getProfile();
        $form_data['leader_name'] = $manager_profile->getFullname();
        $form_data['leader_email'] = $manager_profile->getEmail();
      }
      $this->form->bind($form_data);
      if($this->form->isValid())
      {
        $pm_projects = $this->form->save();
        $new_project_id = $pm_projects->getId();
        $new_project_name = $pm_projects->getName();

        // add build default file groups
        $build_default_files =
        array (
            1=>'Plan and Specs',
            2=>'Images',
            3=>'Variation',
            4=>'Others',
            5=>'Tender'
        );
        if($build_default_files) {
          foreach($build_default_files as $file)
          {
            $newfilelist = new pmProjectObjects();
            $newfilelist->setModule('resources list');
            $newfilelist->setProjectId($new_project_id);
            $newfilelist->setName($file);
            $newfilelist->setCreatedById($sf_user_id);
            $newfilelist->setCreatedByName($sf_user_fullname);
            $newfilelist->save();
          }
        }
        // add new project entry into form table
        $project_form = new pmForms();
        $project_form->setProjectId($new_project_id);
        $project_form->setName($new_project_name);
        $project_form->setCreatedById($user_id);
        $project_form->save();

        // add new client into new build
        $project_clients = new pmProjectUsers();
        $project_clients->setProjectId($new_project_id);
        $project_clients->setUserId($client_user_id);
        $project_clients->setCreatedAt(date('Y-m-d H:i:s'));
        $project_clients->save();

        $project_leader = new pmProjectUsers();
        $project_leader->setProjectId($new_project_id);
        $project_leader->setUserId($leader_id);
        $project_leader->setCreatedAt(date('Y-m-d H:i:s'));
        $project_leader->save();

        $modification_message = 'Add Client to new Build, Build Name: '.$new_project_name.' Id: '.$new_project_id;
        $this->saveHistory($modification_message, $client_user_id);

        $this->getUser()->setFlash('notice','"'.$new_project_name.'" has been created successfully');
        $this->redirect('build/show?id='.$new_project_id.'&project_id='.$new_project_id);
      }
      $this->setTemplate('build');
    }
  }

  public function executeValidateFnm($request)
  {
    //      $is_branch_owner = $this->$sf_is_branch_owner;
    $is_branch_owner = $this->sf_is_branch_owner;
    $render_text = array("message"=>"");
    $user_id = $this->sf_user_id;

    $branch_id = $branch_ids = null;
    $fname = $request->getParameter('profile_fname', null);
    $lname = $request->getParameter('profile_lname', null);
    $email = $request->getParameter('profile_email', null);
    $phone = $request->getParameter('profile_phone', null);
    $mobile = $request->getParameter('profile_mobile', null);
    $id =  $request->getParameter('id');
    $phone = preg_replace('/\D/', '', $phone);
    $mobile = preg_replace('/\D/', '', $mobile);

    $branch_id = $request->getParameter('branch_id');

    $branch_ids = $this->sf_owner_branch_ids;

    //    var_dump($branch_id);exit;
    if (!$branch_id && !$is_branch_owner || count($branch_ids) == 1) {
      $branch_id = $this->getUser()->getUserBranch()->getId();
    }
    $c = new Criteria();
    $c->addJoin(ProfilePeer::USER_ID, sfGuardUserPeer::ID);
    $c->addJoin(sfGuardUserPeer::ID, sfGuardUserGroupPeer::USER_ID);
    $c->addJoin(sfGuardGroupPeer::ID, sfGuardUserGroupPeer::GROUP_ID);
    $c->addJoin(sfGuardUserPeer::ID, BranchUsersPeer::USER_ID);
    $c->add(sfGuardGroupPeer::NAME, 'client');

    if($id){
      $c->add(ProfilePeer::ID, $id, Criteria::NOT_EQUAL);
    }

    $c->add(BranchUsersPeer::BRANCH_ID, $branch_id);

    $alert_msg = sfConfig::get('mod_client_messages_alert_msg');

    if ($fname) {
      $c->add(ProfilePeer::FNAME, trim($fname));

      $exists = ProfilePeer::doSelect($c);
      $fname_links = ' or to view the client, select';
      foreach ($exists as $fname_exist) {
        //          $names = $fname_exist->getFname().' '.$fname_exist->getLname();
        //          echo link_to($names, 'client/show?id='.$fname_exist->getId(), array());
        $fname_links .= ' <a href="show/id/'.$fname_exist->getId().'"><strong> '.$fname_exist->getFname().' '.$fname_exist->getLname().' </strong></a>';
      }
      if($exists) {
        $render_text['message'] = '<strong>'.$fname.'</strong> '.$alert_msg.' '.$fname_links;
      }
    }

    if ($lname) {
      $c->add(ProfilePeer::LNAME, trim($lname));

      $exists = ProfilePeer::doSelect($c);
      $lname_links = ' or to view the client, select';
      foreach ($exists as $lname_exist) {
        $lname_links .= ' <a href="show/id/'.$lname_exist->getId().'"><strong> '.$lname_exist->getFname().' '.$lname_exist->getLname().' </strong></a>';
      }
      if($exists) {
        $render_text['message'] = '<strong>'.$lname.'</strong> '.$alert_msg.' '.$lname_links;
      }
    }

    if ($email) {
      $c->add(ProfilePeer::EMAIL, trim($email));

      $exists = ProfilePeer::doSelect($c);
      $email_links = ' or to view the client, select';
      foreach ($exists as $email_exist) {
        $email_links .= ' <a href="show/id/'.$email_exist->getId().'"><strong> '.$email_exist->getFname().' '.$email_exist->getLname().' </strong></a>';
      }
      if($exists) {
        $render_text['message'] = '<strong>'.$email.'</strong> '.$alert_msg.' '.$email_links;
      }
    }

    if ($phone) {
      $exists = ProfilePeer::doSelect($c);
      $phone_existing = false;
      $phone_links = ' or to view the client, select';
      foreach ($exists as $phone_exist) {
        $all_phone = preg_replace('/\D/', '', $phone_exist->getPhone());
        if (strpos($all_phone, $phone) !== false) {
          $phone_existing = true;
          $phone_links .= ' <a href="show/id/'.$phone_exist->getId().'"><strong> '.$phone_exist->getFname().' '.$phone_exist->getLname().' - ('. $phone_exist->getPhone() .') </strong></a>';
        }
      }
      if ($phone_existing) {
        $render_text['message'] = '<strong>'.$phone.'</strong> '.$alert_msg.' '.$phone_links;
      }
    }

    if ($mobile) {
      $mobile_exists = ProfilePeer::doSelect($c);
      $mobile_existing = false;
      $mobile_links = ' or to view the client, select';
      foreach ($mobile_exists as $mobile_exist) {
        $all_mobile = preg_replace('/\D/', '', $mobile_exist->getMobile());
        if (strpos($all_mobile, $mobile) !== false) {
          $mobile_existing = true;
          $mobile_links .= ' <a href="show/id/'.$mobile_exist->getId().'"><strong> '.$mobile_exist->getFname().' '.$mobile_exist->getLname().' - ('. $mobile_exist->getMobile() .') </strong></a>';
        }
      }
      if ($mobile_existing) {
        $render_text['message'] = '<strong>'.$mobile.'</strong> '.$alert_msg.' '.$mobile_links;
      }
    }

    if($request->isXmlHttpRequest())
    {
      return $this->renderText(json_encode($render_text));
    }
    return sfView::NONE;
     
  }

  public function executeValidateUnm($request)
  {
    $username = $request->getParameter('username');
    $this->username = $username;
    $id = $this->getRequestParameter('id');

    $this->user_name = '';
    $c = new Criteria();
    $c->addJoin(sfGuardUserPeer::ID, ProfilePeer::USER_ID);
    $c->add(ProfilePeer::ID, $id, Criteria::NOT_EQUAL);
    $c->add(sfGuardUserPeer::USERNAME, $username);
    $guarduser = sfGuardUserPeer::doSelectOne($c);
    $render_text['message'] =  $guarduser?$username.' already exists, please choose another username':'';

    if($request->isXmlHttpRequest())
    {
      return $this->renderText(json_encode($render_text));
    }
    return sfView::NONE;
  }

  private function getCalendar($event)
  {
    $user_id = $event->getUserId();

    $c = new Criteria();
    $c->add(calendarsPeer::USER_ID, $user_id);
    $c->setLimit(1);
    return calendarsPeer::doSelectOne($c);
  }

  private function getICalData($event)
  {
    // set Your unique id
    $config = array( 'unique_id' => 'ravebuild.com' );
    $caldesc_string = "RaveBuild-Calendar Events";
    // create a new calendar instance
    $v_calendar = new vcalendar( $config );
    // set download file name
    $v_calendar->filename = md5($event->getId()).'.ics';

    // required of some calendar software
    $v_calendar->setProperty( 'method', 'PUBLISH' );
    $v_calendar->setProperty('X-WR-CALNAME;VALUE=TEXT', $caldesc_string);
    $v_calendar->setProperty( "X-WR-CALDESC", "This is ". $caldesc_string );
    $v_calendar->setProperty( "X-WR-TIMEZONE", "Pacific/Auckland" );

    $start_time = strtotime($event->getStartTime());
    $end_time = strtotime($event->getStartTime());
    $end_hour = date('H', strtotime($event->getEndTime()));

    // star time
    $start = array( 'year'=>date('Y', $start_time), 'month'=>date('m', $start_time),
        'day'=>date('d', $start_time), 'hour'=>date('H', $start_time), 'min'=>0, 'sec'=>0 );

    // end time
    $end = array( 'year'=>date('Y', $end_time), 'month'=>date('m', $end_time),
        'day'=>date('d', $end_time), 'hour'=>$end_hour, 'min'=>0, 'sec'=>0 );
    $v_event = new vevent(); // create an event calendar component
    $v_event->setProperty('uid', md5($event->getId()));
    //$v_event->setProperty("recurrence-id", $start);
    $v_event->setProperty('created', $event->getCreatedAt());
    $v_event->setProperty('last-modified', $event->getUpdatedAt());
    $v_event->setProperty('dtstart', $start);
    $v_event->setProperty('dtend', $end);
    $v_event->setProperty('summary', $event->getSubject());
    $v_event->setProperty('description', $event->getBody());
    $v_calendar->setComponent($v_event);

    return $v_calendar;
  }

  private function setIcalEvents($v_calendar, $calendar_events)
  {
    foreach($calendar_events as $events_cal)
    {
      $v_calendar->setComponent($events_cal);
    }
  }

  /**
   * Create calendar
   */
  private function createCalendar()
  {
    $user = $this->getUser();
    $user_id = $user->getGuardUser()->getId();
    $user_name = $user->getGuardUser()->getUsername();
    // delete events which are not associated with any user
    $this->deleteDummyEvents();

    $c = new Criteria();
    $c->add(calendarsPeer::USER_ID, $user->getId());
    $calendar = calendarsPeer::doSelectOne($c);

    if(!$calendar)
    {
      $config = sfConfig::get('app_calendars_default');

      $cal = new calendars();

      $principal_uri = str_replace('{username}', $user_name, $config['principlal_uri']);

      $cal->setUserId($user_id);
      $cal->setPrincipalUri($principal_uri);
      $cal->setDisplayName($config['name']);
      $cal->setUri($config['uri']);
      $cal->save();

      $this->updateEvents($user_id(), $cal->getId());
    }
  }



  /**
   * update message satus on comment sender basis
   * @param String $comment_sender
   * @param Integer $message_id
   */
  private function updateMessageStatusOnComment($comment_sender, $message_id)
  {
    $c = new Criteria();
    $c->add(MessagesPeer::ID, $message_id);
    $c->addJoin(MessagesPeer::SENDER, sfGuardUserPeer::USERNAME);
    $user = sfGuardUserPeer::doSelectOne($c);
    $sender_id = $user->getId();

    // if Message owner
    if ($user->getUsername() == $comment_sender)
    {
      $c = new Criteria();
      $c->add(MessageReceiversPeer::MESSAGE_ID, $message_id);
      $c->add(MessageReceiversPeer::USER_ID, $sender_id, Criteria::NOT_EQUAL);
      $receivers = MessageReceiversPeer::doSelect($c);
      foreach ($receivers as $receiver)
      {
        $receiver->setSeen(false);
        $receiver->save();
      }
    }
    else
    {
      $c = new Criteria();
      $c->add(MessageReceiversPeer::MESSAGE_ID, $message_id);
      $c->add(MessageReceiversPeer::USER_ID, $sender_id);
      if(!$receiver = MessageReceiversPeer::doSelectOne($c))
      {
        $receiver = new MessageReceivers();
        $receiver->setMessageId($message_id);
        $receiver->setUserId($sender_id);
      }
      $receiver->setSeen(false);
      $receiver->save();
    }
  }

  /**
   * Save Client Profile History in log table
   * @param Integer $client_id
   * @param String $modification_message
   */
  public function saveHistory($modification_message, $client_user_id)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_user_id = $sf_guard_user->getId();
    $sf_user_profile = $sf_guard_user->getProfile();
    $sf_full_name = $sf_user_profile->getFullname();
    $sf_user_mail = $sf_user_profile->getEmail();

    $log = new pmActivityLogs();
    $log->setObjectId($client_user_id);
    $log->setModifications($modification_message);
    $log->setAction($modification_message);
    $log->setCreatedById($sf_user_id);
    $log->setCreatedAt(date('Y-m-d H:i:s'));
    $log->setCreatedByName($sf_full_name);
    $log->setCreatedByEmail($sf_user_mail);
    $log->save();
  }

  public function executeEditclientevent($request)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_guard_userid = $sf_guard_user->getId();
    $branch_id = $sf_user->getUserBranch()->getId();
    $client_id = $request->getParameter('id');
    $event_id = $request->getParameter('event_id');
    $event_details = '';
    if($event_id) {
      $event_details = pmProjectObjectsPeer::retrieveByPK($event_id);
      $this->form = new ClientEventForm($event_details);
    }
    else {
      $this->form = new ClientEventForm();
    }

    $staff_detail = $this->getUser();

    $sale_person = array();
    $sale_person[$sf_guard_userid] = $staff_detail->getProfile()->getFullname();

    $staff_persons = ProfilePeer::getBranchUsers($branch_id, sfGuardGroupPeer::BRANCH_OFFICE_STAFF);

    foreach($staff_persons as $staff_person)
    {
      $sale_person[$staff_person->getUserId()] = $staff_person->getFname().' '.$staff_person->getLname();
    }

    $this->staff_id = $sale_person;
    $this->default_staff = '';

    if($event_details)
    {
      if($event_details->getContractId())
      {
        $this->default_staff = $event_details->getContractId();
      }
    }
    else
    {
      $this->default_staff = $sf_guard_userid;
    }
  }

  public function executeCnotes($request)
  {
    $client_id = $request->getParameter('id');
    $this->is_new = true;
    $this->note_form = new clientNotesForm();
    $notes_id = $request->getParameter('nid');
    if($notes_id)
    {
      $notes_details = clientNotesPeer::retrieveByPK($notes_id);
      $this->note_form = new clientNotesForm($notes_details);
      $this->is_new = false;
    }
     
    $c = new Criteria();
    $c->add(clientNotesPeer::USER_ID, $client_id, Criteria::EQUAL);
    $c->addDescendingOrderByColumn(clientNotesPeer::ID);
    $this->client_notes = clientNotesPeer::doSelect($c);
  }

  public function executeUpdatenotes($request)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_guard_userid = $sf_guard_user->getId();
    $branch_id = $sf_user->getUserBranch()->getId();

    $client_profile_id = $request->getParameter('id');
    $client_user_id = ProfilePeer::getClientUserId($client_profile_id);

    if($request->isMethod('post'))
    {
      $form_data = $request->getParameter('client_notes');
      $form_data_updated = $request->getParameter('client_notes_user_date');
      if(empty($form_data_updated)) {
        $form_data['updated_at'] = date('Y-m-d H:i:s');
      } else {
        $form_data['updated_at'] = date('Y-m-d H:i:s', strtotime($form_data_updated.' '.date('H:i:s')));
      }
      $form_data['updated_by_id'] = $sf_guard_userid;

      if(!empty($form_data['id'])) {
        $notes_detail = clientNotesPeer::retrieveByPK($form_data['id']);
        $this->form = new clientNotesForm($notes_detail);
      } else {
        $this->form = new clientNotesForm();
        $form_data['created_by_id'] = $sf_guard_userid;
      }
      $this->form->bind($form_data);
      if($this->form->isValid())
      {
        if(!empty($form_data['id'])) {
          $conn = Propel::getConnection();

          $client_notes_c = new Criteria();
          $client_notes_c->add(ClientNotesPeer::ID, $form_data['id']);

          $cn_new = new Criteria();
          $cn_new->add(ClientNotesPeer::NOTES, $form_data['notes']);
          $cn_new->add(ClientNotesPeer::UPDATED_AT, $form_data['updated_at']);
          $cn_new->add(ClientNotesPeer::UPDATED_BY_ID, $sf_guard_userid);

          $message = sprintf(sfConfig::get("mod_client_notes_updatemessage"),$notes_detail->getNotes(),$form_data['notes']);
          BasePeer::doUpdate($client_notes_c, $cn_new, $conn);
          $sf_user->setAttribute('note'.$form_data['id'], time());
        } else {
          $message = sfConfig::get("mod_client_notes_addmessage");
          $new_note = $this->form->save();
          $sf_user->setAttribute('note'.$new_note->getId(), time());
        }
        $this->saveHistory($message, $client_user_id);
      }
    }

    $this->redirect('client/show?id='.$client_profile_id.'#notes_container');

  }

  /**
   * Execute when custom criteria option selected from client criteria of clients page.
   * @param sfWebRequest $request
   */
  public function executeClientinfo($request)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_guard_userid = $sf_guard_user->getId();
    $branch_id = $sf_user->getUserBranch()->getId();

    $this->keepstay = '';
    $this->search_value = 0;
    $this->client_ranks = array();
    $this->sales = array();

    // retrieve all opportunity
    $this->client_ranks  =  clientRankPeer::getClientRankList($branch_id);
    if($this->client_ranks) {
      array_unshift($this->client_ranks, 'All');
    }

    // retrieve sales person of login branch
    $sales_users = $sf_user->getBranchOfficeStaffUsers();
    foreach($sales_users as $sales_user)
    {
      $sales_lists[$sales_user->getId()] = $sales_user->getProfile()->getFullname();
    }

    $this->created_by = $sf_guard_userid;
    $this->sales = $sales_lists;
  }


  /**
   * Call when click on Export to csv (client info)
   * @param  sfWebRequest $request
   */
  public function executeExport($request)
  {

    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_guard_userid = $sf_guard_user->getId();
    $branch_id = $sf_user->getUserBranch()->getId();

    // retrieve value of form post
    $form_data = $request->getParameter('field_name');
    $search = $request->getParameter('search');

    $search_type = $search['type'];
    $search_value = $search[$search_type];
    if($search_value == '')
    {
      $search_value = $search['keyword'];
    }


    $csv_output = '';
    $header = array();
    $data_row = array();
    $column_names =  sfConfig::get('mod_client_csvcolumns_options');


    // set client criteria
    $c = new Criteria();
    $c->clearSelectColumns();
    $c->addJoin(sfGuardUserPeer::ID, sfGuardUserGroupPeer::USER_ID);
    $c->addJoin(sfGuardGroupPeer::ID, sfGuardUserGroupPeer::GROUP_ID);
    $c->addJoin(sfGuardUserPeer::ID, ProfilePeer::USER_ID);
    $c->addJoin(sfGuardUserPeer::ID, BranchUsersPeer::USER_ID);
    $c->add(sfGuardGroupPeer::NAME, sfGuardGroupPeer::CLIENT);
    $c->add(BranchUsersPeer::BRANCH_ID, $branch_id);
    $c->addJoin( ProfilePeer::RANK, clientRankPeer::RANK_ID, Criteria::LEFT_JOIN);
    $c->add(clientRankPeer::BRANCH_ID, $branch_id, Criteria::EQUAL);
    //In case of opportunity is selected, also check user is selected all or any particular opportunity client, 0 is for all
    if($search_type == 'krank' && $search_value!=0)
    {
      $c->add(ProfilePeer::RANK, $search_value, Criteria::EQUAL);
    }
    if($search_type == 'ksales')
    {
      $c->add(ProfilePeer::SALES_ID, $search_value, Criteria::EQUAL);
    }

    foreach($form_data as $key=>$value)
    {
      if($value != "" )
      {
        $header[$value] = $column_names[$value];
        if($value == 'sales_id'){
          $c->addAlias('p1',ProfilePeer::TABLE_NAME);
          $c->addJoin(ProfilePeer::SALES_ID, 'p1.user_id', Criteria::LEFT_JOIN);
          $c->addSelectColumn('concat(p1.fname," ", p1.lname) as salesperson');
        }
        elseif($value == 'rank'){
          $c->addSelectColumn('if(length(RANK_DETAILS)>0, concat(RANK_NAME,"-",RANK_DETAILS), RANK_NAME)');
        } else {
          $c->addSelectColumn('replace('.ProfilePeer::translateFieldName($value, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_COLNAME).',",","#")');
        }
      }
    }

    $rs = ProfilePeer::doSelectRs($c);
    if($rs)
    {
      $csv_output = implode(',',$header)."\n";
      while ($rs->next())
      {
        $data_row = array_values($rs->getRow());
        $csv_output.= implode(',', $data_row)."\n";
      }
    }
    $this->downloadCSV($csv_output);
  }



  /**
   * Receive csv data through parameter and set the header of csv file.
   * @param string $csv_output
   */
  private function downloadCSV($csv_output)
  {
    $file = 'client_info';
    $filename = $file."_".date("Y_m_d_H_i_a");
    if ($csv_output) {
      header('Content-Type: plain/csv');
      header('Content-Disposition: filename='.$filename.".csv");
      header('Content-Length: ' . strlen($csv_output));
      print($csv_output);
      die();

    }
  }


  /**
   * Save selected branch id in session variable, for client save in case brach owner is handling more than one branch.
   * @param webrequest $request
   * @return nothing
   */
  public function executeSavebranch($request)
  {
    $sf_user = $this->getUser();
    $branch_id = $request->getParameter("bid");
    $sf_user->setAttribute('branch_id',$branch_id);
    $this->clientopportunity = clientRankPeer::getClientRankList($branch_id);
  }

  /**
   * Allocate build to client files, so files can display in build also.
   */
  public function executeUpdateFileProjectId($request)
  {
    $id = $request->getParameter('id');
    $po_id =  $request->getParameter('poid');
    $pid = $request->getParameter('pid');

    if($po_id)
    {
      sfLoader::loadHelpers('Utilities');
      $pm_project_object  = pmProjectObjectsPeer::retrieveByPK($po_id);
      $old_project_id = $pm_project_object->getProjectId();
      $pm_project_object->setProjectId($pid);
      $pm_project_object->save();
      $s3_service = new AmazonS3Service();
      $source_filename = get_project_foldername($pm_project_object->getPjLotno(), 'pj_lotno').DIRECTORY_SEPARATOR.$pm_project_object->getTextField1();
      $dest_filename = get_project_foldername($pid).DIRECTORY_SEPARATOR.$pm_project_object->getTextField1();
      // $source = sfConfig::get('sf_upload_dir').'/'.get_project_foldername($pm_project_object->getPjLotno(), 'pj_lotno')."/".$pm_project_object->getTextField1();
      // $dest = sfConfig::get('sf_upload_dir').'/'.get_project_foldername($pid).'/'.$pm_project_object->getTextField1();
      $s3_service->copyObject($source_filename, $dest_filename);


      $source = sfConfig::get('sf_upload_dir').'/'.get_project_foldername($pm_project_object->getPjLotno(), 'pj_lotno')."/".$pm_project_object->getTextField1();
      $dest = sfConfig::get('sf_upload_dir').'/'.get_project_foldername($pid).'/'.$pm_project_object->getTextField1();
      try
      {
        //    copy($source,$dest);
        //   unlink($source);
      }
      catch(Exception $e)
      {
        //   echo 'Caught exception: ',  $e->getMessage(), "\n";
      }
    }

    if($request->isXmlHttpRequest())
   	{
   	  $contents = get_component('client', 'client_uploaded_files');
   	  return $this->renderText($contents);
   	}

    return sfView::NONE;
  }

  public function executeFile($request)
  {
    $this->form = new ClientFileForm();
  }

  public function executeUpdateclientstatus($request)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_guard_userid = $sf_guard_user->getId();
     
    $id = $request->getParameter('id');
    $opportunity = $request->getParameter('status');
    $reason = $request->getParameter('r', null);
    $parent = $request->getParameter('p', 0);
     
    $profile_details = ProfilePeer::retrieveByPK($id);
    $client_branch_id = BranchUsersPeer::getUserBranchId($profile_details->getUserId());
    $this->getCORid = '';
    if($sf_user->hasBranchAccess())
    {
      $client_opportunity_log = new ClientOpportunityLog();
      $updateCor = false;

      $cor = new Criteria();
      $cor->add(ClientOpportunityRecordPeer::USER_ID, $profile_details->getUserId());
      $getCorLists = ClientOpportunityRecordPeer::doSelect($cor);

      $client_opportunity_record = new ClientOpportunityRecord();

      if($parent)
      {
        $sub_opportunities = SubOpportunityPeer::retrieveByPK($opportunity);
        $opportunity_id = $sub_opportunities->getOpportunityId();
        $sub_opportunity_id = $sub_opportunities->getId();
        $opportunity_details = clientRankPeer::retrieveByPK($opportunity_id);
        $profile_details->setRank($opportunity_details->getRankId());
        $profile_details->setSubOpportunity($opportunity);
        $client_opportunity_log->setOpportunityId($opportunity_details->getRankId());
        $client_opportunity_log->setSubOpportunityId($opportunity);

        $client_opportunity_record->setOpportunityId($opportunity_details->getRankId());
        $client_opportunity_record->setSubOpportunityId($opportunity);
        $client_opportunity_record->setUserId($profile_details->getUserId());
        $client_opportunity_record->setCreatedById($sf_guard_userid);
        $client_opportunity_record->setUpdatedById($sf_guard_userid);

        if (!empty($getCorLists)) {
          foreach ($getCorLists as $cor) {
            if ($cor->getOpportunityId() == $opportunity_details->getRankId() &&
                $cor->getSubOpportunityId() == $sub_opportunity_id) {
              $updateCor = true;
              //                        break;
            }
          }

          if ($updateCor) {
            $conn = Propel::getConnection();

            $client_opportunity_record_criteria = new Criteria();
            $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::USER_ID, $profile_details->getUserId());
            $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::OPPORTUNITY_ID, $opportunity_details->getRankId());
            $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::SUB_OPPORTUNITY_ID, $sub_opportunity_id);

            $cor_new = new Criteria();
            $cor_new->add(ClientOpportunityRecordPeer::UPDATED_AT, date('Y-m-d H:i:s'));
            $cor_new->add(ClientOpportunityRecordPeer::UPDATED_BY_ID, $sf_guard_userid);

            BasePeer::doUpdate($client_opportunity_record_criteria, $cor_new, $conn);
            $clientORupdated = ClientOpportunityRecordPeer::doSelectOne($client_opportunity_record_criteria);
            $this->getCORid = $clientORupdated->getId();
          } else {
            $client_opportunity_record->save();
            $this->getCORid = $client_opportunity_record->getId();
          }
        } else {
          $client_opportunity_record->save();
          $this->getCORid = $client_opportunity_record->getId();
        }
      } else {
        $profile_details->setRank($opportunity);
        $profile_details->setSubOpportunity(null);
        $client_opportunity_log->setOpportunityId($opportunity);
        $client_opportunity_log->setSubOpportunityId(null);

        $client_opportunity_record->setUserId($profile_details->getUserId());
        $client_opportunity_record->setOpportunityId($opportunity);
        $client_opportunity_record->setSubOpportunityId(null);
        $client_opportunity_record->setCreatedById($sf_guard_userid);
        $client_opportunity_record->setUpdatedById($sf_guard_userid);

        if (!empty($getCorLists)) {
          foreach ($getCorLists as $cor) {
            if ($cor->getOpportunityId() == $opportunity && $cor->getSubOpportunityId() == null) {
              $updateCor = true;
              //                        break;
            }
          }

          if ($updateCor) {
            $conn = Propel::getConnection();

            $client_opportunity_record_criteria = new Criteria();
            $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::USER_ID, $profile_details->getUserId());
            $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::OPPORTUNITY_ID, $opportunity);

            $cor_new = new Criteria();
            $cor_new->add(ClientOpportunityRecordPeer::UPDATED_AT, date('Y-m-d H:i:s'));
            $cor_new->add(ClientOpportunityRecordPeer::UPDATED_BY_ID, $sf_guard_userid);

            BasePeer::doUpdate($client_opportunity_record_criteria, $cor_new, $conn);
            $clientORupdated = ClientOpportunityRecordPeer::doSelectOne($client_opportunity_record_criteria);
            $this->getCORid = $clientORupdated->getId();
          } else {
            $client_opportunity_record->save();
            $this->getCORid = $client_opportunity_record->getId();
          }
        } else {
          $client_opportunity_record->save();
          $this->getCORid = $client_opportunity_record->getId();
        }
      }

      if($reason) {
        $profile_details->setClientrank($reason);
        $profile_details->setLead(ClientLeadPeer::getBranchLostId($client_branch_id));

      }
      $profile_details->save();


      $client_opportunity_log->setUserId($profile_details->getUserId());
      $client_opportunity_log->setCreatedById($sf_guard_userid);
      $client_opportunity_log->save();

      //            $client_opportunity_record->save();
    }

    //  	$profile_detail = ProfilePeer::retrieveByPK($id);

    //        $crod = new Criteria();
    //        $crod->add(ClientOpportunityRecordPeer::USER_ID, $profile_detail->getUserId());
    //        $crod->addDescendingOrderByColumn(ClientOpportunityRecordPeer::UPDATED_AT);
    //        $client_opportunity_record_details = ClientOpportunityRecordPeer::doSelectOne($crod);
    //        $subOppId = false;
    //        //get client opportunity records
    //        $oppDetails = array();
    //        if (!empty($client_opportunity_record_details)) {
    //            $oppc = new Criteria();
    //            $oppc->add(clientRankPeer::RANK_ID, $client_opportunity_record_details->getOpportunityId());
    //            $oppc->add(clientRankPeer::BRANCH_ID, $client_branch_id);
    //            $opp = clientRankPeer::doSelectOne($oppc);
    //            $subOppId = $client_opportunity_record_details->getSubOpportunityId();
    //
    //            if ($subOppId) {
      //                $subOpp = SubOpportunityPeer::retrieveByPK($subOppId);
      //                $oppDetails = array(
    //                    'client_opp_record_id'  =>  $client_opportunity_record_details->getId(),
    //                    'opportunity_id' => $client_opportunity_record_details->getOpportunityId(),
    //                    'opportunity_name' => $opp->getRankDescription(),
    //                    'sub_opportunity_id' => $subOppId,
    //                    'sub_opportunity_name' => $subOpp->getName(),
    //                    'opportunity_updated_at' => date('d-m-Y', strtotime($client_opportunity_record_details->getUpdatedAt())),
    //                    'opportunity_updated_by' => $client_opportunity_record_details->getUpdatedById()
    //                );
    //            } else {
    //                $oppDetails = array(
    //                    'client_opp_record_id'  =>  $client_opportunity_record_details->getId(),
    //                    'opportunity_id' => $client_opportunity_record_details->getOpportunityId(),
    //                    'opportunity_name' => $opp->getRankDescription(),
    //                    'sub_opportunity_id' => '',
    //                    'sub_opportunity_name' => '',
    //                    'opportunity_updated_at' => date('d-m-Y', strtotime($client_opportunity_record_details->getUpdatedAt())),
    //                    'opportunity_updated_by' => $client_opportunity_record_details->getUpdatedById()
    //                );
    //            }
    //        }

    //        $returnProfile = array(
    //                                'client_rank_name' => $profile_detail->getClientRankName(),
    //                                'client_opportunity_records' => $oppDetails
    //                            );
    //        $this->getResponse()->setHttpHeader('Content-type', 'application/json');
    //  	return $this->renderText(json_encode($returnProfile));
    $sf_user->setAttribute($this->getCORid, time());
    $this->updatetime = $sf_user->getAttribute($this->getCORid);

    $text = $this->getComponent('client', 'clientprofilecomponent_ajax', array('id'=> $id, 'getCORid'=>$this->getCORid, 'updatetime'=>time()));
    return $this->renderText($text);
  }

  public function executeUpdateclientstatuswon($request)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_guard_userid = $sf_guard_user->getId();
     
    $id = $request->getParameter('id');
    $opportunity = $request->getParameter('status');
    if ($this->getRequestParameter('signed_contract_value')) {
      $signed_value = $this->getRequestParameter('signed_contract_value');
    } else {
      $signed_value = $request->getParameter('contractValue', null);
    }

    if ($this->getRequestParameter('signed_contract_date')) {
      $signed_date = $this->getRequestParameter('signed_contract_date');
    } else {
      $signed_date = $request->getParameter('contractDate', null);
    }
    if(!empty($signed_date)) {
      $signed_date = strtotime($signed_date);
    }
    $profile_details = ProfilePeer::retrieveByPK($id);
    $client_branch_id = BranchUsersPeer::getUserBranchId($profile_details->getUserId());

    //        $c_pmproject = new Criteria();
    //        $c_pmproject->add(pmProjectUsersPeer::USER_ID, $profile_details->getUserId());
    //        $c_pmproject->addDescendingOrderByColumn(pmProjectUsersPeer::CREATED_AT);
    //        $c_pj_id = pmProjectUsersPeer::doSelectOne($c_pmproject);

    if($sf_user->hasBranchAccess())
    {
      $client_opportunity_log = new ClientOpportunityLog();
      $updateCor = false;

      $cor = new Criteria();
      $cor->add(ClientOpportunityRecordPeer::USER_ID, $profile_details->getUserId());
      $cor->add(ClientOpportunityRecordPeer::OPPORTUNITY_ID, $opportunity);
      $getCorLists = ClientOpportunityRecordPeer::doSelect($cor);

      $client_opportunity_record = new ClientOpportunityRecord();

      $profile_details->setRank($opportunity);
      $profile_details->setSubOpportunity(null);
      $client_opportunity_log->setOpportunityId($opportunity);
      $client_opportunity_log->setSubOpportunityId(null);

      $client_opportunity_record->setUserId($profile_details->getUserId());
      $client_opportunity_record->setOpportunityId($opportunity);
      $client_opportunity_record->setSubOpportunityId(null);
      $client_opportunity_record->setCreatedById($sf_guard_userid);
      $client_opportunity_record->setUpdatedById($sf_guard_userid);

      $conn = Propel::getConnection();
      if (!empty($getCorLists)) {

        $client_opportunity_record_criteria = new Criteria();
        $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::USER_ID, $profile_details->getUserId());
        $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::OPPORTUNITY_ID, $opportunity);

        $cor_new = new Criteria();
        $cor_new->add(ClientOpportunityRecordPeer::UPDATED_AT, date('Y-m-d', $signed_date));
        $cor_new->add(ClientOpportunityRecordPeer::UPDATED_BY_ID, $sf_guard_userid);

        BasePeer::doUpdate($client_opportunity_record_criteria, $cor_new, $conn);

        $clientORupdated = ClientOpportunityRecordPeer::doSelectOne($client_opportunity_record_criteria);
        $this->getCORid = $clientORupdated->getId();
      } else {
        $client_opportunity_record->save();
        $this->getCORid = $client_opportunity_record->getId();
      }

      if (!empty($signed_value)) {
        $cpp = new Criteria();
        $cpp->add(pmProjectsPeer::CLIENT_ID, $profile_details->getUserId());

        $cpp_new = new Criteria();
        $cpp_new->add(pmProjectsPeer::ACTUAL_BUILD_COST, $signed_value);
        $cpp_new->add(pmProjectsPeer::UPDATED_BY_ID, $sf_guard_userid);
        $cpp_new->add(pmProjectsPeer::UPDATED_AT, date('Y-m-d H:i:s'));

        BasePeer::doUpdate($cpp, $cpp_new, $conn);
      }

      $profile_details->save();

      $client_opportunity_log->setUserId($profile_details->getUserId());
      $client_opportunity_log->setCreatedById($sf_guard_userid);
      $client_opportunity_log->save();
    }

    $sf_user->setAttribute($this->getCORid, time());
    $this->updatetime = $sf_user->getAttribute($this->getCORid);

    $text = $this->getComponent('client', 'clientprofilecomponent_ajax', array('id'=> $id, 'getCORid'=>$this->getCORid, 'updatetime'=>time()));
    return $this->renderText($text);
  }
  public function executeGetclientstatus($request) {
    $id = $request->getParameter('id');
    $profile_detail = ProfilePeer::retrieveByPK($id);
    $returnProfile['client_rank_name'] = $profile_detail->getClientRankName();

    $this->getResponse()->setHttpHeader('Content-type', 'application/json');
    return $this->renderText(json_encode($returnProfile));
  }

  public function executeBranchranks($request) {
    $text = $this->getComponent('client', 'branchrank', array('branchId'=> $request->getParameter('branchId')));
    return $this->renderText($text);
  }

  public function executeBranchstaffs($request) {
    $text = $this->getComponent('client', 'branchstaff', array('branchId'=> $request->getParameter('branchId')));
    return $this->renderText($text);
  }

  public function executeBranchleads($request) {
    $text = $this->getComponent('client', 'branchlead', array('branchId'=> $request->getParameter('branchId')));
    return $this->renderText($text);
  }

  private function getBranchRanks($branch_id) {
    $brArray = array();

    if ($branch_id) {
      $c = new Criteria();
      $c->add(clientRankPeer::BRANCH_ID, $branch_id);
      $c->addAscendingOrderByColumn(clientRankPeer::RANK_ID);
      $branch_ranks = clientRankPeer::doSelect($c);
      $this->sub_opportunity_exist = 0;

      foreach ($branch_ranks as $branch_rank) {
        $cbr = new Criteria();
        $cbr->add(SubOpportunityPeer::OPPORTUNITY_ID, $branch_rank->getId());
        $branch_sub_ranks = SubOpportunityPeer::doSelect($cbr);

        if (!empty($branch_sub_ranks)) {
          $brArray[$branch_rank->getRankId()][0] = $branch_rank->getRankName();
          foreach ($branch_sub_ranks as $branch_sub_rank) {
            $brArray[$branch_rank->getRankId()][$branch_sub_rank->getId()] = $branch_sub_rank->getName();

          }
        } else {
          $brArray[$branch_rank->getRankId()][0] = $branch_rank->getRankName();
        }
      }
    }
    return $brArray;
  }
  
  /**
   * Import clients details from CSV files
   *
   * @param sfWebRequest $request
   */
  public function executeImportclients($request)
  {
    $user = $this->getUser();
    $column_heading     = array();
    $data_rows          = array();
    $import_info        = $request->getParameter('import');
    $email_notification = true;
    $is_header_row      = (boolean) $import_info['header_row'];
    $this->errors       = array();
    $this->passed       = '';
    $this->failed       = '';
    $this->_branch      = $request->getParameter('_bid', 0);
     
    if($user->hasAttribute($this->upload_attribute))
    {
      $filename = $user->getAttribute($this->upload_attribute);
      $csv_importer    = new CSVImporter();
      $success_message = sfConfig::get('mod_client_upload_message');
      $column_heading  = $csv_importer->readCSVColumns($filename, $success_message);
      $data_rows       = $csv_importer->readCSVColumns($filename, '', false);
    }
     
    $this->column_heading = $column_heading;
    $this->data_rows      = $data_rows;
    $form_data = $request->getParameter('field_name');
     
    if($form_data && $user->hasAttribute($this->upload_attribute))
    {
      $filename = $user->getAttribute($this->upload_attribute);
      $user->getAttributeHolder()->remove($this->upload_attribute);
      $this->column_heading = array();
      $csv_importer = new CSVImporter($user, CSVImporter::IMPORT_CLIENTS);
      $response = $csv_importer->saveCSVFileData($filename, $form_data, $is_header_row, $email_notification);
       
      if(empty($response[0]))
      {
        $this->redirect('client/index');
      }
      else
      {
        $this->errors = $response[0];
        $this->passed = $response[1];
        $this->failed = $response[2];
      }
    }
     
    $this->file_attribute = $this->upload_attribute;
  }
  
  /**
   * Cancel upload request after/before user uploaded
   * CSV file
   *
   * @param sfWebRequest $request
   */
  public function executeCancelupload($request)
  {
    $user = $this->getUser();
    if($user->hasAttribute($this->upload_attribute))
    {
      $filename = $user->getAttribute($this->upload_attribute);
      $filename = sfConfig::get('sf_upload_dir').'/'.$filename;
      $user->getAttributeHolder()->remove($this->upload_attribute);
      unlink($filename);
    }
     
    $this->redirect('client/importclients');
  }

  /**
   * 
   * @param unknown $request
   * @return Ambigous <sfView::NONE, string>
   */
  public function executeUpdateclientopprecord($request)
  {
    $sf_user = $this->getUser();
    $sf_guard_user = $sf_user->getGuardUser();
    $sf_guard_userid = $sf_guard_user->getId();
     
    $cor_id = $request->getParameter('id');
    $cor_date = strtotime($request->getParameter('cor_date'));
     
    if ($sf_user->hasBranchAccess()) {
      $conn = Propel::getConnection();

      $client_opportunity_record_criteria = new Criteria();
      $client_opportunity_record_criteria->add(ClientOpportunityRecordPeer::ID, $cor_id);

      $cor_new = new Criteria();
      $cor_new->add(ClientOpportunityRecordPeer::UPDATED_AT, $cor_date);
      $cor_new->add(ClientOpportunityRecordPeer::UPDATED_BY_ID, $sf_guard_userid);

      BasePeer::doUpdate($client_opportunity_record_criteria, $cor_new, $conn);
    }

    $corupdated = ClientOpportunityRecordPeer::retrieveByPK($cor_id);
    $cor_date_at = $corupdated->getUpdatedAt();

    $client_opportunity_log = new ClientOpportunityLog();
    $client_opportunity_log->setOpportunityId($corupdated->getOpportunityId());
    $client_opportunity_log->setSubOpportunityId($corupdated->getSubOpportunityId());
    $client_opportunity_log->setUserId($corupdated->getUserId());
    $client_opportunity_log->setCreatedById($sf_guard_userid);
    $client_opportunity_log->save();

    return $this->renderText(date('d-m-Y', strtotime($cor_date_at)));
  }
  
  public function executeGetmarketingoptions($request)
  {
    $branch_id = 0;
    $this->marketing_options = sfConfig::get('mod_client_marketing_options');
    $branch_id = $request->getParameter('branch_id');
    if($branch_id) {
      $branch_id = $request->getParameter('branch_id');
      $branch_service = new BranchService($branch_id, $this->sf_user_id);
      $this->marketing_options = $branch_service->getMarketingOptionList();
      //            $this->marketing_options = array(1=>'a', 2=>'b', 3=>'c',4=>'d', 5=>'e', 6=>'f', 7=>'g');
    }
  
    $this->getResponse()->setHttpHeader('Content-type', 'application/json');
    return $this->renderText(json_encode($this->marketing_options));
  }
}
