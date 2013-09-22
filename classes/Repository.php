<?php

class Repository {

    const VISIBILITY_PUBLIC = 1;
    const VISIBILITY_PRIVATE = 2;
    const ROLE_NONE = 0;
    const ROLE_ADMINISTRATOR = 1;
    const ROLE_DEVELOPER = 2;
    const ROLE_MODERATOR = 3;
    const ROLE_CONTRIBUTOR = 4;

    protected $id;
    protected $name;
    protected $visibility;
    protected $defaultLanguage;
    /**
     * List of all language data objects
     *
     * @var array[Language]
     */
    protected $languages;

    public function __construct($id, $name, $visibility, $defaultLanguage) {
        $this->id = $id;
        $this->name = $name;
        $this->visibility = $visibility;
        $this->defaultLanguage = $defaultLanguage;
        $this->languages = array();
        $languages = Language::getList();
        foreach ($languages as $language) {
            $this->languages[$language] = new Language_Android($language);
        }
    }

    /**
     * @return int ID of the project
     */
    public function getID() {
        return $this->id;
    }

    /**
     * @return string name of the project
     */
    public function getName() {
        return $this->name;
    }

    /**
     * @return int visibility ID of the project
     */
    public function getVisibility() {
        return $this->visibility;
    }

    public function getVisibilityTitle() {
        return self::getRepositoryVisibilityTitle($this->visibility);
    }

    public static function getRepositoryVisibilityTitle($visibilityID) {
        switch ($visibilityID) {
            case self::VISIBILITY_PUBLIC:
                return 'public';
            case self::VISIBILITY_PRIVATE:
                return 'private';
            default:
                throw new Exception('Unknown visibility ID: '.$visibilityID);
        }
    }

    public function getVisibilityDescription() {
        return self::getRepositoryVisibilityDescription($this->visibility);
    }

    public static function getRepositoryVisibilityDescription($visibilityID) {
        switch ($visibilityID) {
            case self::VISIBILITY_PUBLIC:
                return 'Visible to all signed-in users';
            case self::VISIBILITY_PRIVATE:
                return 'Visible to invited users only';
            default:
                throw new Exception('Unknown visibility ID: '.$visibilityID);
        }
    }

    public function getVisibilityTag() {
        return self::getRepositoryVisibilityTag($this->visibility);
    }

    public static function getRepositoryVisibilityTag($visibilityID) {
        return '<abbr title="'.self::getRepositoryVisibilityDescription($visibilityID).'">'.self::getRepositoryVisibilityTitle($visibilityID).'</abbr>';
    }

    public function getDefaultLanguage() {
        return $this->defaultLanguage;
    }

    public function getPermissions($userID, $role) {
        return new RepositoryPermissions($userID, $this->visibility, $role);
    }

    public function addPhrase($language, $id, $phraseKey, $payload, $enabled = true, $createKeysOnly = false) {
        $this->languages[$language]->addPhrase(Phrase::create($id, $phraseKey, $payload, $enabled, $createKeysOnly));
    }

    public function removePhrase($language, $phraseKey) {
        $this->languages[$language]->removePhrase($phraseKey);
    }

    public function normalizePhrase($language, $phraseKey, $referencePhrase) {
        $this->languages[$language]->normalizePhrase($phraseKey, $referencePhrase);
    }

    public function loadLanguages($isExport = false) {
        $phrases = Database::select("SELECT id, languageID, phraseKey, enabled, payload FROM phrases WHERE repositoryID = ".intval($this->id));
        if (!empty($phrases)) {
            foreach ($phrases as $phrase) {
                $this->addPhrase($phrase['languageID'], $phrase['id'], $phrase['phraseKey'], $phrase['payload'], $phrase['enabled']);
            }
        }
        $this->normalizeLanguages($isExport);
    }

    protected function normalizeLanguages($isExport) {
        $defLangObject = $this->languages[$this->defaultLanguage];
        $defLangPhrases = $defLangObject->getPhrases();
        foreach ($this->languages as $langID => $lang) {
            if ($lang != $this->defaultLanguage) {
                $currentPhrases = $lang->getPhrases();
                foreach ($currentPhrases as $currentPhrase) { // loop through phrases of all non-default languages
                    $originalPhrase = $defLangObject->getPhraseByKey($currentPhrase->getPhraseKey());
                    if (!isset($originalPhrase)) { // if phrase does not exist in default language
                        $this->removePhrase($langID, $currentPhrase->getPhraseKey()); // remove from this language as well
                    }
                    elseif ($isExport) { // if phrase does exist in default language as well and this is an export
                        $this->normalizePhrase($langID, $currentPhrase->getPhraseKey(), $originalPhrase); // normalize phrase with default language data
                    }
                }
                foreach ($defLangPhrases as $defLangPhrase) { // loop through phrases of default language
                    $currentPhrase = $lang->getPhraseByKey($defLangPhrase->getPhraseKey());
                    if (!isset($currentPhrase)) { // if phrase does not exist in this language yet
                        $this->addPhrase($langID, $defLangPhrase->getID(), $defLangPhrase->getPhraseKey(), $defLangPhrase->getPayload(), $defLangPhrase->isEnabledForTranslation(), !$isExport); // add phrase from default language
                    }
                }
            }
        }
    }

    /**
     * Get the language data object for the given language ID
     *
     * @param int $languageID ID of the language to fetch the data object for
     * @return Language the language object for the given ID
     * @throws Exception if no language with the given ID could be found
     */
    public function getLanguage($languageID) {
        if (isset($this->languages[$languageID])) {
            return $this->languages[$languageID];
        }
        else {
            throw new Exception('Unknown language ID '.$languageID);
        }
    }

    public static function hasUserPermissions($userID, $repositoryID, $repositoryData, $requiredRole) {
        $repository = new Repository($repositoryID, $repositoryData['name'], $repositoryData['visibility'], $repositoryData['defaultLanguage']);
        $role = Database::getRepositoryRole(Authentication::getUserID(), $repositoryID);
        $permissions = $repository->getPermissions($userID, $role);
        if (Authentication::getUserID() > 0 && !$permissions->isInvitationMissing()) {
            if ($role != self::ROLE_NONE && $role <= $requiredRole) {
                return true;
            }
        }
        return false;
    }

    public static function isRoleAllowedToMovePhrases($role) {
        if ($role == Repository::ROLE_ADMINISTRATOR || $role == Repository::ROLE_DEVELOPER) {
            return true;
        }
        else {
            return false;
        }
    }

}

class RepositoryPermissions {

    private $userID;
    private $repositoryVisibility;
    private $role;

    public function __construct($userID, $repositoryVisibility, $role) {
        $this->userID = $userID;
        $this->repositoryVisibility = $repositoryVisibility;
        $this->role = $role;
    }

    public function isInvitationMissing() {
        if ($this->repositoryVisibility == Repository::VISIBILITY_PUBLIC) {
            return false;
        }
        elseif ($this->repositoryVisibility == Repository::VISIBILITY_PRIVATE) {
            if ($this->role == Repository::ROLE_ADMINISTRATOR) {
                return false;
            }
            elseif ($this->role == Repository::ROLE_DEVELOPER) {
                return false;
            }
            elseif ($this->role == Repository::ROLE_MODERATOR) {
                return false;
            }
            elseif ($this->role == Repository::ROLE_CONTRIBUTOR) {
                return false;
            }
            elseif ($this->role == Repository::ROLE_NONE) {
                return true;
            }
            else {
                throw new Exception('Unknown role '.$this->role);
            }
        }
        else {
            throw new Exception('Unknown visibility ID '.$this->repositoryVisibility);
        }
    }

}