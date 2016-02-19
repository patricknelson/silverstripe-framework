<?php
/**
 * A TinyMCE-powered WYSIWYG HTML editor field with image and link insertion and tracking capabilities. Editor fields
 * are created from <textarea> tags, which are then converted with JavaScript.
 *
 * @package forms
 * @subpackage fields-formattedinput
 */
class HtmlEditorField extends TextareaField {

	/**
	 * Use TinyMCE's GZIP compressor
	 *
	 * @config
	 * @var bool
	 */
	private static $use_gzip = true;

	/**
	 * @config
	 * @var bool Should we check the valid_elements (& extended_valid_elements) rules from HtmlEditorConfig server side?
	 */
	private static $sanitise_server_side = false;

	/**
	 * Number of rows
	 *
	 * @var int
	 */
	protected $rows = 30;

	/**
	 * @deprecated since version 4.0
	 */
	public static function include_js() {
		Deprecation::notice('4.0', 'Use HtmlEditorConfig::require_js() instead');
		HtmlEditorConfig::require_js();
	}


	protected $editorConfig = null;

	/**
	 * Creates a new HTMLEditorField.
	 * @see TextareaField::__construct()
	 *
	 * @param string $name The internal field name, passed to forms.
	 * @param string $title The human-readable field label.
	 * @param mixed $value The value of the field.
	 * @param string $config HTMLEditorConfig identifier to be used. Default to the active one.
	 */
	public function __construct($name, $title = null, $value = '', $config = null) {
		parent::__construct($name, $title, $value);

		$this->editorConfig = $config ? $config : HtmlEditorConfig::get_active_identifier();
	}

	public function getAttributes() {
		return array_merge(
			parent::getAttributes(),
			array(
				'tinymce' => 'true',
				'style'   => 'width: 97%; height: ' . ($this->rows * 16) . 'px', // prevents horizontal scrollbars
				'value' => null,
				'data-config' => $this->editorConfig
			)
		);
	}

	public function saveInto(DataObjectInterface $record) {
		if($record->hasField($this->name) && $record->escapeTypeForField($this->name) != 'xml') {
			throw new Exception (
				'HtmlEditorField->saveInto(): This field should save into a HTMLText or HTMLVarchar field.'
			);
		}

		// Resample images
		$value = Image::regenerate_html_links($this->value);
		$htmlValue = Injector::inst()->create('HTMLValue', $value);

		// Sanitise if requested
		if($this->config()->sanitise_server_side) {
			$santiser = Injector::inst()->create('HtmlEditorSanitiser', HtmlEditorConfig::get_active());
			$santiser->sanitise($htmlValue);
		}

		// optionally manipulate the HTML after a TinyMCE edit and prior to a save
		$this->extend('processHTML', $htmlValue);

		// Store into record
		$record->{$this->name} = $htmlValue->getContent();
	}

	/**
	 * @return HtmlEditorField_Readonly
	 */
	public function performReadonlyTransformation() {
		$field = $this->castedCopy('HtmlEditorField_Readonly');
		$field->dontEscape = true;

		return $field;
	}

	public function performDisabledTransformation() {
		return $this->performReadonlyTransformation();
	}
}

/**
 * Readonly version of an {@link HTMLEditorField}.
 * @package forms
 * @subpackage fields-formattedinput
 */
class HtmlEditorField_Readonly extends ReadonlyField {
	public function Field($properties = array()) {
		$valforInput = $this->value ? Convert::raw2att($this->value) : "";
		return "<span class=\"readonly typography\" id=\"" . $this->id() . "\">"
			. ( $this->value && $this->value != '<p></p>' ? $this->value : '<i>(not set)</i>' )
			. "</span><input type=\"hidden\" name=\"".$this->name."\" value=\"".$valforInput."\" />";
	}
	public function Type() {
		return 'htmleditorfield readonly';
	}
}

/**
 * Toolbar shared by all instances of {@link HTMLEditorField}, to avoid too much markup duplication.
 *  Needs to be inserted manually into the template in order to function - see {@link LeftAndMain->EditorToolbar()}.
 *
 * @package forms
 * @subpackage fields-formattedinput
 */
class HtmlEditorField_Toolbar extends RequestHandler {

	private static $allowed_actions = array(
		'LinkForm',
		'MediaForm',
		'viewfile',
		'getanchors'
	);

	/**
	 * @var string
	 */
	protected $templateViewFile = 'HtmlEditorField_viewfile';

	protected $controller, $name;

	public function __construct($controller, $name) {
		parent::__construct();

		Requirements::javascript(FRAMEWORK_DIR . "/thirdparty/jquery/jquery.js");
		Requirements::javascript(THIRDPARTY_DIR . '/jquery-ui/jquery-ui.js');
		Requirements::javascript(THIRDPARTY_DIR . '/jquery-entwine/dist/jquery.entwine-dist.js');
		Requirements::javascript(FRAMEWORK_ADMIN_DIR . '/javascript/dist/ssui.core.js');

		HtmlEditorConfig::require_js();
		Requirements::javascript(FRAMEWORK_DIR ."/javascript/dist/HtmlEditorField.js");

		Requirements::css(THIRDPARTY_DIR . '/jquery-ui-themes/smoothness/jquery-ui.css');

		$this->controller = $controller;
		$this->name = $name;
	}

	public function forTemplate() {
		return sprintf(
			'<div id="cms-editor-dialogs" data-url-linkform="%s" data-url-mediaform="%s"></div>',
			Controller::join_links($this->controller->Link(), $this->name, 'LinkForm', 'forTemplate'),
			Controller::join_links($this->controller->Link(), $this->name, 'MediaForm', 'forTemplate')
		);
	}

	/**
	 * Searches the SiteTree for display in the dropdown
	 *
	 * @return callback
	 */
	public function siteTreeSearchCallback($sourceObject, $labelField, $search) {
		return DataObject::get($sourceObject)->filterAny(array(
			'MenuTitle:PartialMatch' => $search,
			'Title:PartialMatch' => $search
		));
	}

	/**
	 * Return a {@link Form} instance allowing a user to
	 * add links in the TinyMCE content editor.
	 *
	 * @return Form
	 */
	public function LinkForm() {
		$siteTree = TreeDropdownField::create('internal', _t('HtmlEditorField.PAGE', "Page"),
			'SiteTree', 'ID', 'MenuTitle', true);
		// mimic the SiteTree::getMenuTitle(), which is bypassed when the search is performed
		$siteTree->setSearchFunction(array($this, 'siteTreeSearchCallback'));

		$numericLabelTmpl = '<span class="step-label"><span class="flyout">%d</span><span class="arrow"></span>'
			. '<strong class="title">%s</strong></span>';
		$form = new Form(
			$this->controller,
			"{$this->name}/LinkForm",
			new FieldList(
				$headerWrap = new CompositeField(
					new LiteralField(
						'Heading',
						sprintf('<h3 class="htmleditorfield-mediaform-heading insert">%s</h3>',
							_t('HtmlEditorField.LINK', 'Insert Link'))
					)
				),
				$contentComposite = new CompositeField(
					OptionsetField::create(
						'LinkType',
						sprintf($numericLabelTmpl, '1', _t('HtmlEditorField.LINKTO', 'Link to')),
						array(
							'internal' => _t('HtmlEditorField.LINKINTERNAL', 'Page on the site'),
							'external' => _t('HtmlEditorField.LINKEXTERNAL', 'Another website'),
							'anchor' => _t('HtmlEditorField.LINKANCHOR', 'Anchor on this page'),
							'email' => _t('HtmlEditorField.LINKEMAIL', 'Email address'),
							'file' => _t('HtmlEditorField.LINKFILE', 'Download a file'),
						),
						'internal'
					),
					LiteralField::create('Step2',
						'<div class="step2">'
						. sprintf($numericLabelTmpl, '2', _t('HtmlEditorField.DETAILS', 'Details')) . '</div>'
					),
					$siteTree,
					TextField::create('external', _t('HtmlEditorField.URL', 'URL'), 'http://'),
					EmailField::create('email', _t('HtmlEditorField.EMAIL', 'Email address')),
					$fileField = UploadField::create('file', _t('HtmlEditorField.FILE', 'File')),
					TextField::create('Anchor', _t('HtmlEditorField.ANCHORVALUE', 'Anchor')),
					TextField::create('Subject', _t('HtmlEditorField.SUBJECT', 'Email subject')),
					TextField::create('Description', _t('HtmlEditorField.LINKDESCR', 'Link description')),
					CheckboxField::create('TargetBlank',
						_t('HtmlEditorField.LINKOPENNEWWIN', 'Open link in a new window?')),
					HiddenField::create('Locale', null, $this->controller->Locale)
				)
			),
			new FieldList()
		);

		$headerWrap->addExtraClass('CompositeField composite cms-content-header nolabel ');
		$contentComposite->addExtraClass('ss-insert-link content');
		$fileField->setAllowedMaxFileNumber(1);

		$form->unsetValidator();
		$form->loadDataFrom($this);
		$form->addExtraClass('htmleditorfield-form htmleditorfield-linkform cms-mediaform-content');

		$this->extend('updateLinkForm', $form);

		return $form;
	}

	/**
	 * Get the folder ID to filter files by for the "from cms" tab
	 *
	 * @return int
	 */
	protected function getAttachParentID() {
		$parentID = $this->controller->getRequest()->requestVar('ParentID');
		$this->extend('updateAttachParentID', $parentID);
		return $parentID;
	}

	/**
	 * Return a {@link Form} instance allowing a user to
	 * add images and flash objects to the TinyMCE content editor.
	 *
	 * @return Form
	 */
	public function MediaForm() {
		// TODO Handle through GridState within field - currently this state set too late to be useful here (during
		// request handling)
		$parentID = $this->getAttachParentID();

		$fileFieldConfig = GridFieldConfig::create()->addComponents(
			new GridFieldFilterHeader(),
			new GridFieldSortableHeader(),
			new GridFieldDataColumns(),
			new GridFieldPaginator(7),
			// TODO Shouldn't allow delete here, its too confusing with a "remove from editor view" action.
			// Remove once we can fit the search button in the last actual title column
			new GridFieldDeleteAction(),
			new GridFieldDetailForm()
		);
		$fileField = GridField::create('Files', false, null, $fileFieldConfig);
		$fileField->setList($this->getFiles($parentID));
		$fileField->setAttribute('data-selectable', true);
		$fileField->setAttribute('data-multiselect', true);
		$columns = $fileField->getConfig()->getComponentByType('GridFieldDataColumns');
		$columns->setDisplayFields(array(
			'StripThumbnail' => false,
			'Title' => _t('File.Title'),
			'Created' => singleton('File')->fieldLabel('Created'),
		));
		$columns->setFieldCasting(array(
			'Created' => 'SS_Datetime->Nice'
		));

		$fromCMS = new CompositeField(
			$select = TreeDropdownField::create('ParentID', "", 'Folder')
				->addExtraClass('noborder')
				->setValue($parentID),
			$fileField
		);

		$fromCMS->addExtraClass('content ss-uploadfield htmleditorfield-from-cms');
		$select->addExtraClass('content-select');


		$URLDescription = _t('HtmlEditorField.URLDESCRIPTION', 'Insert videos and images from the web into your page simply by entering the URL of the file. Make sure you have the rights or permissions before sharing media directly from the web.<br /><br />Please note that files are not added to the file store of the CMS but embeds the file from its original location, if for some reason the file is no longer available in its original location it will no longer be viewable on this page.');
		$fromWeb = new CompositeField(
			$description = new LiteralField('URLDescription', '<div class="url-description">' . $URLDescription . '</div>'),
			$remoteURL = new TextField('RemoteURL', 'http://'),
			new LiteralField('addURLImage',
				'<button type="button" class="action ui-action-constructive ui-button field font-icon-plus add-url">' .
				_t('HtmlEditorField.BUTTONADDURL', 'Add url').'</button>')
		);

		$remoteURL->addExtraClass('remoteurl');
		$fromWeb->addExtraClass('content ss-uploadfield htmleditorfield-from-web');

		Requirements::css(FRAMEWORK_DIR . '/css/AssetUploadField.css');
		$computerUploadField = Object::create('UploadField', 'AssetUploadField', '');
		$computerUploadField->setConfig('previewMaxWidth', 40);
		$computerUploadField->setConfig('previewMaxHeight', 30);
		$computerUploadField->addExtraClass('ss-assetuploadfield htmleditorfield-from-computer');
		$computerUploadField->removeExtraClass('ss-uploadfield');
		$computerUploadField->setTemplate('HtmlEditorField_UploadField');
		$computerUploadField->setFolderName(Config::inst()->get('Upload', 'uploads_folder'));
		
		$defaultPanel = new CompositeField(
			$computerUploadField,
			$fromCMS
		);
		
		$fromWebPanel = new CompositeField(
			$fromWeb
		);
		
		$defaultPanel->addExtraClass('htmleditorfield-default-panel');
		$fromWebPanel->addExtraClass('htmleditorfield-web-panel');

		$allFields = new CompositeField(
			$defaultPanel,
			$fromWebPanel,
			$editComposite = new CompositeField(
				new LiteralField('contentEdit', '<div class="content-edit ss-uploadfield-files files"></div>')
			)
		);

		$allFields->addExtraClass('ss-insert-media');

		$headings = new CompositeField(
			new LiteralField(
				'Heading',
				sprintf('<h3 class="htmleditorfield-mediaform-heading insert">%s</h3>',
					_t('HtmlEditorField.INSERTMEDIA', 'Insert media from')).
				sprintf('<h3 class="htmleditorfield-mediaform-heading update">%s</h3>',
					_t('HtmlEditorField.UpdateMEDIA', 'Update media'))
			)
		);

		$headings->addExtraClass('cms-content-header');
		$editComposite->addExtraClass('ss-assetuploadfield');

		$fields = new FieldList(
			$headings,
			$allFields
		);

		$form = new Form(
			$this->controller,
			"{$this->name}/MediaForm",
			$fields,
			new FieldList()
		);


		$form->unsetValidator();
		$form->disableSecurityToken();
		$form->loadDataFrom($this);
		$form->addExtraClass('htmleditorfield-form htmleditorfield-mediaform cms-dialog-content');

		// Allow other people to extend the fields being added to the imageform
		$this->extend('updateMediaForm', $form);

		return $form;
	}

	/**
	 * View of a single file, either on the filesystem or on the web.
	 *
	 * @param SS_HTTPRequest $request
	 * @return string
	 */
	public function viewfile($request) {
		// TODO Would be cleaner to consistently pass URL for both local and remote files,
		// but GridField doesn't allow for this kind of metadata customization at the moment.
		$file = null;
		if($url = $request->getVar('FileURL')) {
			// URLS should be used for remote resources (not local assets)
			$url = Director::absoluteURL($url);
		} elseif($id = $request->getVar('ID')) {
			// Use local dataobject
			$file = DataObject::get_by_id('File', $id);
			if(!$file) {
				throw new InvalidArgumentException("File could not be found");
			}
			$url = $file->getURL();
			if(!$url) {
				return $this->httpError(404, 'File not found');
			}
		} else {
			throw new LogicException('Need either "ID" or "FileURL" parameter to identify the file');
		}

		// Instanciate file wrapper and get fields based on its type
		// Check if appCategory is an image and exists on the local system, otherwise use oEmbed to refference a
		// remote image
		$fileCategory = File::get_app_category(File::get_file_extension($url));
		switch($fileCategory) {
			case 'image':
			case 'image/supported':
				$fileWrapper = new HtmlEditorField_Image($url, $file);
				break;
			case 'flash':
				$fileWrapper = new HtmlEditorField_Flash($url, $file);
				break;
			default:
				// Only remote files can be linked via o-embed
				// {@see HtmlEditorField_Toolbar::getAllowedExtensions())
				if($file) {
					throw new InvalidArgumentException(
						"Oembed is only compatible with remote files"
					);
				}
				// Other files should fallback to oembed
				$fileWrapper = new HtmlEditorField_Embed($url, $file);
				break;
		}

		// Render fields and return
		$fields = $this->getFieldsForFile($url, $fileWrapper);
		return $fileWrapper->customise(array(
			'Fields' => $fields,
		))->renderWith($this->templateViewFile);
	}

	/**
	 * Find all anchors available on the given page.
	 *
	 * @return array
	 */
	public function getanchors() {
		$id = (int)$this->getRequest()->getVar('PageID');
		$anchors = array();

		if (($page = Page::get()->byID($id)) && !empty($page)) {
			if (!$page->canView()) {
				throw new SS_HTTPResponse_Exception(
					_t(
						'HtmlEditorField.ANCHORSCANNOTACCESSPAGE',
						'You are not permitted to access the content of the target page.'
					),
					403
				);
			}

			// Similar to the regex found in HtmlEditorField.js / getAnchors method.
			if (preg_match_all(
				"/\\s+(name|id)\\s*=\\s*([\"'])([^\\2\\s>]*?)\\2|\\s+(name|id)\\s*=\\s*([^\"']+)[\\s +>]/im",
				$page->Content,
				$matches
			)) {
				$anchors = array_values(array_unique(array_filter(
					array_merge($matches[3], $matches[5]))
				));
			}

		} else {
			throw new SS_HTTPResponse_Exception(
				_t('HtmlEditorField.ANCHORSPAGENOTFOUND', 'Target page not found.'),
				404
			);
		}

		return json_encode($anchors);
	}

	/**
	 * Similar to {@link File->getCMSFields()}, but only returns fields
	 * for manipulating the instance of the file as inserted into the HTML content,
	 * not the "master record" in the database - hence there's no form or saving logic.
	 *
	 * @param string $url Abolute URL to asset
	 * @param HtmlEditorField_File $file Asset wrapper
	 * @return FieldList
	 */
	protected function getFieldsForFile($url, HtmlEditorField_File $file) {
		$fields = $this->extend('getFieldsForFile', $url, $file);
		if(!$fields) {
			$fields = $file->getFields();
			$file->extend('updateFields', $fields);
		}
		$this->extend('updateFieldsForFile', $fields, $url, $file);
		return $fields;
	}


	/**
	 * Gets files filtered by a given parent with the allowed extensions
	 *
	 * @param int $parentID
	 * @return DataList
	 */
	protected function getFiles($parentID = null) {
		$exts = $this->getAllowedExtensions();
		$dotExts = array_map(function($ext) { 
			return ".{$ext}";
		}, $exts);
		$files = File::get()->filter('Name:EndsWith', $dotExts);

		// Limit by folder (if required)
		if($parentID) {
			$files = $files->filter('ParentID', $parentID);
		}

		return $files;
	}

	/**
	 * @return Array All extensions which can be handled by the different views.
	 */
	protected function getAllowedExtensions() {
		$exts = array('jpg', 'gif', 'png', 'swf', 'jpeg');
		$this->extend('updateAllowedExtensions', $exts);
		return $exts;
	}

}

/**
 * Encapsulation of a file which can either be a remote URL
 * or a {@link File} on the local filesystem, exhibiting common properties
 * such as file name or the URL.
 *
 * @todo Remove once core has support for remote files
 * @package forms
 * @subpackage fields-formattedinput
 */
abstract class HtmlEditorField_File extends ViewableData {

	/**
	 * Default insertion width for Images and Media
	 *
	 * @config
	 * @var int
	 */
	private static $insert_width = 600;

	/**
	 * Default insert height for images and media
	 *
	 * @config
	 * @var int
	 */
	private static $insert_height = 360;

	/**
	 * Max width for insert-media preview.
	 *
	 * Matches CSS rule for .cms-file-info-preview
	 *
	 * @var int
	 */
	private static $media_preview_width = 176;

	/**
	 * Max height for insert-media preview.
	 *
	 * Matches CSS rule for .cms-file-info-preview
	 *
	 * @var int
	 */
	private static $media_preview_height = 128;

	private static $casting = array(
		'URL' => 'Varchar',
		'Name' => 'Varchar'
	);

	/**
	 * Absolute URL to asset
	 *
	 * @var string
	 */
	protected $url;

	/**
	 * File dataobject (if available)
	 *
	 * @var File
	 */
	protected $file;

	/**
	 * @param string $url
	 * @param File $file
	 */
	public function __construct($url, File $file = null) {
		$this->url = $url;
		$this->file = $file;
		$this->failover = $file;
		parent::__construct();
	}

	/**
	 * @return FieldList
	 */
	public function getFields() {
		$fields = new FieldList(
			CompositeField::create(
				CompositeField::create(LiteralField::create("ImageFull", $this->getPreview()))
					->setName("FilePreviewImage")
					->addExtraClass('cms-file-info-preview'),
				CompositeField::create($this->getDetailFields())
					->setName("FilePreviewData")
					->addExtraClass('cms-file-info-data')
			)
				->setName("FilePreview")
				->addExtraClass('cms-file-info'),
			TextField::create('CaptionText', _t('HtmlEditorField.CAPTIONTEXT', 'Caption text')),
			DropdownField::create(
				'CSSClass',
				_t('HtmlEditorField.CSSCLASS', 'Alignment / style'),
				array(
					'leftAlone' => _t('HtmlEditorField.CSSCLASSLEFTALONE', 'On the left, on its own.'),
					'center' => _t('HtmlEditorField.CSSCLASSCENTER', 'Centered, on its own.'),
					'left' => _t('HtmlEditorField.CSSCLASSLEFT', 'On the left, with text wrapping around.'),
					'right' => _t('HtmlEditorField.CSSCLASSRIGHT', 'On the right, with text wrapping around.')
				)
			),
			FieldGroup::create(_t('HtmlEditorField.IMAGEDIMENSIONS', 'Dimensions'),
				TextField::create(
					'Width',
					_t('HtmlEditorField.IMAGEWIDTHPX', 'Width'),
					$this->getInsertWidth()
				)->setMaxLength(5),
				TextField::create(
					'Height',
					" x " . _t('HtmlEditorField.IMAGEHEIGHTPX', 'Height'),
					$this->getInsertHeight()
				)->setMaxLength(5)
			)->addExtraClass('dimensions last'),
			HiddenField::create('URL', false, $this->getURL()),
			HiddenField::create('FileID', false, $this->getFileID())
		);
		return $fields;
	}

	/**
	 * Get list of fields for previewing this records details
	 *
	 * @return FieldList
	 */
	protected function getDetailFields() {
		$fields = new FieldList(
			ReadonlyField::create("FileType", _t('AssetTableField.TYPE','File type'), $this->getFileType()),
			ReadonlyField::create(
				'ClickableURL', _t('AssetTableField.URL','URL'), $this->getExternalLink()
			)->setDontEscape(true)
		);
		// Get file size
		if($this->getSize()) {
			$fields->insertAfter(
				'FileType',
				ReadonlyField::create("Size", _t('AssetTableField.SIZE','File size'), $this->getSize())
			);
		}
		// Get modified details of local record
		if($this->getFile()) {
			$fields->push(new DateField_Disabled(
				"Created",
				_t('AssetTableField.CREATED', 'First uploaded'),
				$this->getFile()->Created
			));
			$fields->push(new DateField_Disabled(
				"LastEdited",
				_t('AssetTableField.LASTEDIT','Last changed'),
				$this->getFile()->LastEdited
			));
		}
		return $fields;
		
	}

	/**
	 * Get file DataObject
	 *
	 * Might not be set (for remote files)
	 *
	 * @return File
	 */
	public function getFile() {
		return $this->file;
	}

	/**
	 * Get file ID
	 * 
	 * @return int
	 */
	public function getFileID() {
		if($file = $this->getFile()) {
			return $file->ID;
		}
	}

	/**
	 * Get absolute URL
	 *
	 * @return string
	 */
	public function getURL() {
		return $this->url;
	}

	/**
	 * Get basename
	 *
	 * @return string
	 */
	public function getName() {
		return $this->file
			? $this->file->Name
			: preg_replace('/\?.*/', '', basename($this->url));
	}

	/**
	 * Get descriptive file type
	 *
	 * @return string
	 */
	public function getFileType() {
		return File::get_file_type($this->getName());
	}

	/**
	 * Get file size (if known) as string
	 *
	 * @return string|false String value, or false if doesn't exist
	 */
	public function getSize() {
		if($this->file) {
			return $this->file->getSize();
		}
		return false;
	}

	/**
	 * HTML content for preview
	 *
	 * @return string HTML
	 */
	public function getPreview() {
		$preview = $this->extend('getPreview');
		if($preview) {
			return $preview;
		}
		
		// Generate tag from preview
		$thumbnailURL = Convert::raw2att(
			Controller::join_links($this->getPreviewURL(), "?r=" . rand(1,100000))
		);
		$fileName = Convert::raw2att($this->Name);
		return sprintf(
			"<img id='thumbnailImage' class='thumbnail-preview'  src='%s' alt='%s' />\n",
			$thumbnailURL,
			$fileName
		);
	}

	/**
	 * HTML Content for external link
	 *
	 * @return string
	 */
	public function getExternalLink() {
		$title = $this->file
			? $this->file->getTitle()
			: $this->getName();
		return sprintf(
			'<a href="%1$s" title="%2$s" target="_blank" rel="external" class="file-url">%1$s</a>',
			Convert::raw2att($this->url),
			Convert::raw2att($title)
		);
	}

	/**
	 * Generate thumbnail url
	 *
	 * @return string
	 */
	public function getPreviewURL() {
		// Get preview from file
		if($this->file) {
			return $this->getFilePreviewURL();
		}

		// Generate default icon html
		return File::get_icon_for_extension($this->getExtension());
	}

	/**
	 * Generate thumbnail URL from file dataobject (if available)
	 *
	 * @return string
	 */
	protected function getFilePreviewURL() {
		// Get preview from file
		if($this->file) {
			$width = $this->config()->media_preview_width;
			$height = $this->config()->media_preview_height;
			return $this->file->ThumbnailURL($width, $height);
		}
	}

	/**
	 * Get file extension
	 *
	 * @return string
	 */
	public function getExtension() {
		$extension = File::get_file_extension($this->getName());
		return strtolower($extension);
	}

	/**
	 * Category name
	 *
	 * @return string
	 */
	public function appCategory() {
		if($this->file) {
			return $this->file->appCategory();
		} else {
			return File::get_app_category($this->getExtension());
		}
	}

	/**
	 * Get height of this item
	 */
	public function getHeight() {
		if($this->file) {
			$height = $this->file->getHeight();
			if($height) {
				return $height;
			}
		}
		return $this->config()->insert_height;
	}

	/**
	 * Get width of this item
	 *
	 * @return type
	 */
	public function getWidth() {
		if($this->file) {
			$width = $this->file->getWidth();
			if($width) {
				return $width;
			}
		}
		return $this->config()->insert_width;
	}

	/**
	 * Provide an initial width for inserted media, restricted based on $embed_width
	 *
	 * @return int
	 */
	public function getInsertWidth() {
		$width = $this->getWidth();
		$maxWidth = $this->config()->insert_width;
		return ($width <= $maxWidth) ? $width : $maxWidth;
	}

	/**
	 * Provide an initial height for inserted media, scaled proportionally to the initial width
	 *
	 * @return int
	 */
	public function getInsertHeight() {
		$width = $this->getWidth();
		$height = $this->getHeight();
		$maxWidth = $this->config()->insert_width;
		return ($width <= $maxWidth) ? $height : round($height*($maxWidth/$width));
	}

}

/**
 * Encapsulation of an oembed tag, linking to an external media source.
 *
 * @see Oembed
 * @package forms
 * @subpackage fields-formattedinput
 */
class HtmlEditorField_Embed extends HtmlEditorField_File {

	private static $casting = array(
		'Type' => 'Varchar',
		'Info' => 'Varchar'
	);

	/**
	 * Oembed result
	 *
	 * @var Oembed_Result
	 */
	protected $oembed;

	public function __construct($url, File $file = null) {
		parent::__construct($url, $file);
		$this->oembed = Oembed::get_oembed_from_url($url);
		if(!$this->oembed) {
			$controller = Controller::curr();
			$response = $controller->getResponse();
			$response->addHeader('X-Status',
				rawurlencode(_t(
					'HtmlEditorField.URLNOTANOEMBEDRESOURCE',
					"The URL '{url}' could not be turned into a media resource.",
					"The given URL is not a valid Oembed resource; the embed element couldn't be created.",
					array('url' => $url)
				)));
			$response->setStatusCode(404);

			throw new SS_HTTPResponse_Exception($response);
		}
	}

	/**
	 * Get file-edit fields for this filed
	 *
	 * @return FieldList
	 */
	public function getFields() {
		$fields = parent::getFields();
		if($this->Type === 'photo') {
			$fields->insertBefore('CaptionText', new TextField(
				'AltText',
				_t('HtmlEditorField.IMAGEALTTEXT', 'Alternative text (alt) - shown if image can\'t be displayed'),
				$this->Title,
				80
			));
			$fields->insertBefore('CaptionText', new TextField(
				'Title',
				_t('HtmlEditorField.IMAGETITLE', 'Title text (tooltip) - for additional information about the image')
			));
		}
		return $fields;
	}

	/**
	 * Get width of this oembed
	 *
	 * @return int
	 */
	public function getWidth() {
		return $this->oembed->Width ?: 100;
	}

	/**
	 * Get height of this oembed
	 *
	 * @return int
	 */
	public function getHeight() {
		return $this->oembed->Height ?: 100;
	}

	public function getPreviewURL() {
		// Use thumbnail url
		if(!empty($this->oembed->thumbnail_url)) {
			return $this->oembed->thumbnail_url;
		}

		// Use direct image type
		if($this->getType() == 'photo' && !empty($this->Oembed->url)) {
			return $this->Oembed->url;
		}

		// Default media
		return FRAMEWORK_DIR . '/images/default_media.png';
	}

	public function getName() {
		if(isset($this->oembed->title)) {
			return $this->oembed->title;
		} else {
			return parent::getName();
		}
	}

	/**
	 * Get OEmbed type
	 *
	 * @return string
	 */
	public function getType() {
		return $this->oembed->type;
	}

	public function getFileType() {
		return $this->getType()
			?: parent::getFileType();
	}

	/**
	 * @return Oembed_Result
	 */
	public function getOembed() {
		return $this->oembed;
	}

	public function appCategory() {
		return 'embed';
	}

	/**
	 * Info for this oembed
	 *
	 * @return string
	 */
	public function getInfo() {
		return $this->oembed->info;
	}
}

/**
 * Encapsulation of an image tag, linking to an image either internal or external to the site.
 *
 * @package forms
 * @subpackage fields-formattedinput
 */
class HtmlEditorField_Image extends HtmlEditorField_File {

	/**
	 * @var int
	 */
	protected $width;

	/**
	 * @var int
	 */
	protected $height;

	/**
	 * File size details
	 *
	 * @var string
	 */
	protected $size;

	public function __construct($url, File $file = null) {
		parent::__construct($url, $file);

		if($file) {
			return;
		}

		// Get size of remote file
		$size = @filesize($url);
		if($size) {
			$this->size = $size;
		}

		// Get dimensions of remote file
		$info = @getimagesize($url);
		if($info) {
			$this->width = $info[0];
			$this->height = $info[1];
		}
	}

	public function getFields() {
		$fields = parent::getFields();

		// Alt text
		$fields->insertBefore(
			'CaptionText',
			TextField::create(
				'AltText',
				_t('HtmlEditorField.IMAGEALT', 'Alternative text (alt)'),
				$this->Title,
				80
			)->setDescription(
				_t('HtmlEditorField.IMAGEALTTEXTDESC', 'Shown to screen readers or if image can\'t be displayed')
			)
		);

		// Tooltip
		$fields->insertAfter(
			'AltText',
			TextField::create(
				'Title',
				_t('HtmlEditorField.IMAGETITLETEXT', 'Title text (tooltip)')
			)->setDescription(
				_t('HtmlEditorField.IMAGETITLETEXTDESC', 'For additional information about the image')
			)
		);

		return $fields;
	}

	protected function getDetailFields() {
		$fields = parent::getDetailFields();
		$width = $this->getOriginalWidth();
		$height = $this->getOriginalHeight();

		// Show dimensions of original
		if($width && $height) {
			$fields->insertAfter(
				'ClickableURL',
				ReadonlyField::create(
					"OriginalWidth",
					_t('AssetTableField.WIDTH','Width'),
					$width
				)
			);
			$fields->insertAfter(
				'OriginalWidth',
				ReadonlyField::create(
					"OriginalHeight",
					_t('AssetTableField.HEIGHT','Height'),
					$height
				)
			);
		}
		return $fields;
	}

	/**
	 * Get width of original, if known
	 *
	 * @return int
	 */
	public function getOriginalWidth() {
		if($this->width) {
			return $this->width;
		}
		if($this->file) {
			$width = $this->file->getWidth();
			if($width) {
				return $width;
			}
		}
	}

	/**
	 * Get height of original, if known
	 *
	 * @return int
	 */
	public function getOriginalHeight() {
		if($this->height) {
			return $this->height;
		}

		if($this->file) {
			$height = $this->file->getHeight();
			if($height) {
				return $height;
			}
		}
	}

	public function getWidth() {
		if($this->width) {
			return $this->width;
		}
		return parent::getWidth();
	}

	public function getHeight() {
		if($this->height) {
			return $this->height;
		}
		return parent::getHeight();
	}

	public function getSize() {
		if($this->size) {
			return File::format_size($this->size);
		}
		parent::getSize();
	}

	/**
	 * Provide an initial width for inserted image, restricted based on $embed_width
	 *
	 * @return int
	 */
	public function getInsertWidth() {
		$width = $this->getWidth();
		$maxWidth = $this->config()->insert_width;
		return $width <= $maxWidth
			? $width
			: $maxWidth;
	}

	/**
	 * Provide an initial height for inserted image, scaled proportionally to the initial width
	 *
	 * @return int
	 */
	public function getInsertHeight() {
		$width = $this->getWidth();
		$height = $this->getHeight();
		$maxWidth = $this->config()->insert_width;
		return ($width <= $maxWidth) ? $height : round($height*($maxWidth/$width));
	}

	public function getPreviewURL() {
		// Get preview from file
		if($this->file) {
			return $this->getFilePreviewURL();
		}

		// Embed image directly
		return $this->url;
	}
}

/**
 * Generate flash file embed
 */
class HtmlEditorField_Flash extends HtmlEditorField_File {

	public function getFields() {
		$fields = parent::getFields();
		$fields->removeByName('CaptionText', true);
		return $fields;
	}
}
