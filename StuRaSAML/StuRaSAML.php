<?php
/*
 * SAML Authentication plugin for LimeSurvey
 * Copyright (C) 2013 Sixto Pablo Martin Garcia <sixto.martin.garcia@gmail.com>
 * Copyright (C) 2016 Alexander Brychcy <alexander@brychcy.net>
 * License: GNU/GPL License v2 http://www.gnu.org/licenses/gpl-2.0.html
 * URL: https://github.com/pitbulk/limesurvey-saml
 * A plugin of LimeSurvey, a free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

class StuRaSAML extends AuthPluginBase
{
    protected $storage = 'DbStorage';

    protected $ssp = null;
    
    static protected $description = 'StuRa simpleSAML authentication plugin';
    static protected $name = 'StuRa sGIS SAML';
    
    protected $settings = array(
        'simplesamlphp_path' => array(
            'type' => 'string',
            'label' => 'Path to the SimpleSAMLphp folder',
            'default' => '/var/www/tu-ilmenau.de/helfer.stura/simplesamlphp',
        ),
        'saml_authsource' => array(
            'type' => 'string',
            'label' => 'SAML authentication source',
            'default' => 'wayfinder',
        ),
        'saml_uid_mapping' => array(
            'type' => 'string',
            'label' => 'SAML attribute used as username',
            'default' => 'eduPersonPrincipalName',
        ),
        'saml_mail_mapping' => array(
            'type' => 'string',
            'label' => 'SAML attribute used as email',
            'default' => 'mail',
        ),
        'saml_name_mapping' => array(
            'type' => 'string',
            'label' => 'SAML attribute used as name',
            'default' => 'displayName',
        ),
		'saml_groups_mapping' => array(
            'type' => 'string',
            'label' => 'SAML attribute used as groups',
            'default' => 'groups',
        ),
		'saml_permission_mapping'=>array(
            'type'=>'json',
            'label'=>'SAML group attribute to perimission mapping',
            'editorOptions'=>array('mode'=>'tree'),
            'default'=>'{"default":{"create_labelsets":false,"create_participant_panel":false,"create_settings_plugins":false,"create_surveys":false}}',
        ),
        'authtype_base' => array(
            'type' => 'string',
            'label' => 'Authtype base',
            'default' => 'Authdb',
        ),
        'storage_base' => array(
            'type' => 'string',
            'label' => 'Storage base',
            'default' => 'DbStorage',
        ),
        'auto_create_users' => array(
            'type' => 'checkbox',
            'label' => 'Auto create users',
            'default' => true,
        ),
        'auto_update_users' => array(
            'type' => 'checkbox',
            'label' => 'Auto update users',
            'default' => true,
        ),
		'auto_create_groups' => array(
            'type' => 'checkbox',
            'label' => 'Auto create groups',
            'default' => true,
        ),
		'auto_update_groups' => array(
            'type' => 'checkbox',
            'label' => 'Auto update groups',
            'default' => true,
        ),
        'force_saml_login' => array(
            'type' => 'checkbox',
            'label' => 'Force SAML login.',
        )
    );
    
    protected function get_saml_instance() {
        if ($this->ssp == null) {

            $simplesamlphp_path = $this->get('simplesamlphp_path', null, null, '/var/www/simplesamlphp');

            // To avoid __autoload conflicts, remove limesurvey autoloads temporarily 
            $autoload_functions = spl_autoload_functions();
            foreach($autoload_functions as $function) {
                spl_autoload_unregister($function);
            }

            require_once($simplesamlphp_path.'/lib/_autoload.php');

            $saml_authsource = $this->get('saml_authsource', null, null, 'wayfinder');
            $this->ssp = new SimpleSAML_Auth_Simple($saml_authsource);

            // To avoid __autoload conflicts, restote the limesurvey autoloads
            foreach($autoload_functions as $function) {
                spl_autoload_register($function);
            }
	    }
        return $this->ssp;
    }
 
    public function __construct(PluginManager $manager, $id) {
        parent::__construct($manager, $id);

        $this->storage = $this->get('storage_base', null, null, 'DbStorage');

        // Here you should handle subscribing to the events your plugin will handle
        $this->subscribe('beforeLogin');
        $this->subscribe('newUserSession');
        $this->subscribe('afterLogout');

        if (!$this->get('force_saml_login', null, null, false)) {
            $this->subscribe('newLoginForm');
        }
    }

    public function beforeLogin()
    {
        $ssp = $this->get_saml_instance();

        if ($this->get('force_saml_login', null, null, false)) {
            $ssp->requireAuth();
        }
        if ($ssp->isAuthenticated()) {
            $this->setAuthPlugin();
            $this->newUserSession();
        }
    }

    public function afterLogout()
    {
        $ssp = $this->get_saml_instance();
        $ssp->logout(array('ReturnTo' => Yii::app()->getConfig('homeurl')));
    }

    public function newLoginForm()
    {
        $authtype_base = $this->get('authtype_base', null, null, 'Authdb');

        $ssp = $this->get_saml_instance();
        $this->getEvent()->getContent($authtype_base)->addContent('<li><center>Click on that button to initiate SAML Login<br><a href="'.$ssp->getLoginURL().'" title="SAML Login"><img src="'.Yii::app()->getConfig('publicurl').'plugins/StuRaSAML/saml_logo.gif"></a></center><br></li>', 'prepend');
    }

    public function getUserName()
    {
        if ($this->_username == null) {
            $ssp = $this->get_saml_instance();
            $attributes = $this->ssp->getAttributes();
            if (!empty($attributes)) {
                $saml_uid_mapping = $this->get('saml_uid_mapping', null, null, 'uid');
                if (array_key_exists($saml_uid_mapping , $attributes) && !empty($attributes[$saml_uid_mapping])) {
                    $username = $attributes[$saml_uid_mapping][0];
                    $this->setUsername($username);
                }
            }
        }
        return $this->_username;
    }

    public function getUserCommonName()
    {
        $name = '';

        $ssp = $this->get_saml_instance();
        $attributes = $this->ssp->getAttributes();

        if (!empty($attributes)) {
            $saml_name_mapping = $this->get('saml_name_mapping', null, null, 'cn');
            if (array_key_exists($saml_name_mapping , $attributes) && !empty($attributes[$saml_name_mapping])) {
                $name = $attributes[$saml_name_mapping][0];
            }
        }
        return $name;
    }


    public function getUserMail()
    {
        $mail = '';

        $ssp = $this->get_saml_instance();
        $attributes = $this->ssp->getAttributes();
        if (!empty($attributes)) {
            $saml_mail_mapping = $this->get('saml_mail_mapping', null, null, 'mail');
            if (array_key_exists($saml_mail_mapping , $attributes) && !empty($attributes[$saml_mail_mapping])) {
                $mail = $attributes[$saml_mail_mapping][0];
            }
        }
        return $mail;
    }
	
	public function getUserGroups()
    {
        $groups = '';

        $ssp = $this->get_saml_instance();
        $attributes = $this->ssp->getAttributes();
        if (!empty($attributes)) {
            $saml_groups_mapping = $this->get('saml_groups_mapping', null, null, 'groups');
            if (array_key_exists($saml_groups_mapping , $attributes) && !empty($attributes[$saml_groups_mapping])) {
                $groups = $attributes[$saml_groups_mapping];
            }
        }
        return $groups;
    }
	
	public function getLSUserGroups($uid)
	{
		$lsgroups = '';
		
		// Get current Group membership in LimeSurvey
		$qGroup = Yii::app()->db->createCommand();
		$qGroup->select(array('groups.name', 'groups.ugid'));
		$qGroup->from("{{user_in_groups}} AS useringroups");
		$qGroup->where('useringroups.uid='.$uid);
		$qGroup->join("{{user_groups}} AS groups", 'groups.ugid=useringroups.ugid');
		$lsgroups = $qGroup->queryAll();
		
		return $lsgroups;
	}

    public function newUserSession()
    {
        $ssp = $this->get_saml_instance();
        if ($ssp->isAuthenticated()) {

            $sUser = $this->getUserName();
            $_SERVER['REMOTE_USER'] = $sUser;

            $password = createPassword();
            $this->setPassword($password);

            $name = $this->getUserCommonName();
            $mail = $this->getUserMail();
			$groups = $this->getUserGroups();

            $oUser = $this->api->getUserByName($sUser);
            if (is_null($oUser))
            {
                // Create user
                $auto_create_users = $this->get('auto_create_users', null, null, true);
                if ($auto_create_users) {

                    $iNewUID = User::model()->insertUser($sUser, $password, $name, 1, $mail);

                    if ($iNewUID)
                    {
						// Create Groups ?
						$auto_create_groups = $this->get('auto_create_groups', null, null, true);
						if ($auto_create_groups && !empty($groups)) {
							foreach($groups as $iGroup) {
								// FIXME: Ignore sGIS group names longer than 20 chars
								//        Also check similar code in group update part below
								if (strlen($iGroup) > 20) {
									continue;
								}
								
								// Check if group already exists
								$iGroupExists = UserGroup::model()->findByAttributes(array('name' => $iGroup));
								if (!$iGroupExists) {
									// Add new group
									$ugid = UserGroup::model()->addGroup($iGroup, 'autogenerated survey group for sGIS group '.$iGroup);
									// Add new user to new group
									UserInGroup::model()->insertRecords(array('ugid' => $ugid, 'uid' => $iNewUID));
								}
								else {
									// Add new user to existing group
									UserInGroup::model()->insertRecords(array('ugid' => $iGroupExists["ugid"], 'uid' => $iNewUID));
								}
							}
						}
						
                        Permission::model()->insertSomeRecords(array('uid' => $iNewUID, 'permission' => Yii::app()->getConfig("defaulttemplate"),   'entity'=>'template', 'read_p' => 1));

                        // read again user from newly created entry
                        $oUser = $this->api->getUserByName($sUser);

                        $this->setAuthSuccess($oUser);
                    }
                    else {
                        $this->setAuthFailure(self::ERROR_USERNAME_INVALID);
                    }
                }
                else {
                    $this->setAuthFailure(self::ERROR_USERNAME_INVALID);
                }
            } else {
                // Update user?
                $auto_update_users = $this->get('auto_update_users', null, null, true);
                if ($auto_update_users) {
                    $changes = array (
                        'full_name' => $name, 
                        'email' => $mail,
                    );

                    User::model()->updateByPk($oUser->uid, $changes);
                    $oUser = $this->api->getUserByName($sUser);
                }

				// Update groups?
                $auto_update_groups = $this->get('auto_update_groups', null, null, true);
                if ($auto_update_groups) {
					
/* 					// Get current Group membership in LimeSurvey
					$qGroup = Yii::app()->db->createCommand();
					$qGroup->select(array('groups.name', 'groups.ugid'));
					$qGroup->from("{{user_in_groups}} AS useringroups");
					$qGroup->where('useringroups.uid='.$oUser->uid);
					$qGroup->join("{{user_groups}} AS groups", 'groups.ugid=useringroups.ugid');
					$user_in_current_groups = $qGroup->queryAll(); */

					$user_in_current_groups = $this->getLSUserGroups($oUser->uid);
					
					// Reorder Queryarray
					$cGroups = array();
					$cGroupIds = array();
					foreach($user_in_current_groups as $i) {
						array_push($cGroups,$i["name"]);
						array_push($cGroupIds,$i["ugid"]);
					}

					// Groups that the user should be removed
					$groups_diff_del = array_diff($cGroups,$groups);
					if (!empty($groups_diff_del)) {
						foreach($groups_diff_del as $dGroup) {
							$dGroupId = (int) array_search($dGroup,$groups_diff_del);
							$dGroupId = $cGroupIds["$dGroupId"];
							$dUserId = (int) $oUser->uid;
							UserInGroup::model()->deleteByPk(array('ugid' => $dGroupId, 'uid' => $dUserId));
						}
					}
					
					// Groups to add					
					$groups_diff_add = array_diff($groups,$cGroups);
 					if (!empty($groups_diff_add)) {
						foreach($groups_diff_add as $iGroup) {
							// FIXME: Ignore sGIS group names longer than 20 chars
							//        Also check similar code in user creation part above
							if (strlen($iGroup) > 20) {
								continue;
							}
							
							// Check if group already exists
							$iGroupExists = UserGroup::model()->findByAttributes(array('name' => $iGroup));
							$auto_create_groups = $this->get('auto_create_groups', null, null, true);
							if (!$iGroupExists && $auto_create_groups) {
								// Add new group
								$ugid = UserGroup::model()->addGroup($iGroup, 'autogenerated survey group for sGIS group '.$iGroup);
								// Add user to new group
								UserInGroup::model()->insertRecords(array('ugid' => $ugid, 'uid' => $oUser->uid));
							}
							else {
								// Add user to existing group
								UserInGroup::model()->insertRecords(array('ugid' => $iGroupExists["ugid"], 'uid' => $oUser->uid));
							}
						} 
					}
					$oUser = $this->api->getUserByName($sUser);
                }
                $this->setAuthSuccess($oUser);
            }
        }
    }
}
