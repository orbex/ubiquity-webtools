<?php
namespace Ubiquity\controllers\admin\traits;

use Ubiquity\orm\OrmUtils;
use Ubiquity\orm\DAO;
use Ajax\service\JString;
use Ubiquity\controllers\Startup;
use Ajax\semantic\html\modules\checkbox\HtmlCheckbox;
use Ajax\semantic\html\collections\HtmlMessage;
use Ubiquity\controllers\crud\CRUDHelper;
use Ubiquity\controllers\crud\CRUDMessage;
use Ubiquity\utils\http\URequest;
use Ubiquity\utils\http\UResponse;
use Ubiquity\controllers\rest\ResponseFormatter;
use Ajax\semantic\widgets\datatable\Pagination;
use Ubiquity\utils\base\UString;
use Ajax\common\html\HtmlContentOnly;
use Ubiquity\contents\validation\ValidatorsManager;
use Ubiquity\contents\transformation\TransformersManager;
use Ubiquity\cache\CacheManager;
use Ubiquity\cache\ClassUtils;
use Ajax\semantic\components\Toast;

/**
 *
 * @author jc
 * @property \Ajax\JsUtils $jquery
 */
trait ModelsTrait {

	protected $activePage;

	protected $formModal = "no";

	abstract public function _getAdminData();

	abstract public function _getAdminViewer();

	/**
	 *
	 * @return \Ubiquity\controllers\crud\viewers\ModelViewer
	 */
	abstract public function _getModelViewer();

	abstract public function _getFiles();

	abstract public function showSimpleMessage($content, $type, $title = null, $icon = "info", $timeout = NULL, $staticName = null, $closeAction = null, $toast = false): HtmlMessage;

	abstract public function showConfMessage($content, $type, $title, $icon, $url, $responseElement, $data, $attributes = NULL): HtmlMessage;

	public function showModel($model, $id = null) {
		$model = str_replace(".", "\\", $model);
		$adminRoute = $this->_getFiles()->getAdminBaseRoute();
		$this->_showModel($model, $id);
		$metas = OrmUtils::getModelMetadata($model);
		$metas_ = [];
		foreach ($metas as $k => $meta) {
			$metas_[ltrim($k, '#')] = $meta;
		}
		$this->_getAdminViewer()->getModelsStructureDataTable($metas_);
		$this->_getAdminViewer()->getModelsStructureDataTable(ValidatorsManager::getCacheInfo($model), "dtValidation");
		$bt = $this->jquery->semantic()->htmlButton("btYuml", "Class diagram");
		$bt->postOnClick($adminRoute . "/_showDiagram/", "{model:'" . \str_replace("\\", "|", $model) . "'}", "#modal", [
			"attr" => ""
		]);
		$bt = $this->jquery->semantic()->htmlButton("btValidation", "Validate instances");
		$bt->addIcon("check", true, true);
		$bt->postOnClick($adminRoute . "/validateInstances/", "{model:'" . \str_replace("\\", "|", $model) . "'}", "#validationResults", [
			"attr" => "",
			"hasLoader" => "internal"
		]);
		$this->jquery->exec('$("#models-tab .item").tab();', true);
		$this->jquery->getOnClick("#btAddNew", $adminRoute . "/newModel/" . $this->formModal, "#frm-add-update", [
			"hasLoader" => "internal"
		]);
		$this->jquery->compile($this->view);
		$this->loadView($this->_getFiles()
			->getViewShowTable(), [
			"classname" => $model
		]);
	}

	public function validateInstances() {
		$model = $_POST['model'];
		$model = str_replace("|", "\\", $model);
		if (class_exists($model)) {
			ValidatorsManager::start();
			$result = [];
			$instances = DAO::getAll($model, '', false);
			foreach ($instances as $instance) {
				$violations = ValidatorsManager::validate($instance);
				if (sizeof($violations) > 0) {
					$result[] = [
						$instance,
						$violations
					];
				}
			}
			$this->_getAdminViewer()->displayViolations($result);
		} else {
			echo $this->showSimpleMessage("{$model} class does not exists!", "Instances validation", "error", 'error');
		}
	}

	public function refreshTable($id = null) {
		$model = $_SESSION["model"];
		$compo = $this->_showModel($model, $id);
		$this->jquery->execAtLast('$("#table-details").html("");');
		$this->jquery->renderView("@admin/main/elements.html", [
			"compo" => $compo
		]);
	}

	public function showModelClick($modelAndId) {
		$array = \explode("||", $modelAndId);
		if (\is_array($array)) {
			$table = $array[0];
			$id = $array[1];
			$this->jquery->exec("$('#menuDbs .active').removeClass('active');$('.ui.label.left.pointing.teal').removeClass('left pointing teal active');$(\"[data-model='" . $table . "']\").addClass('active');$(\"[data-model='" . $table . "']\").find('.ui.label').addClass('left pointing teal');", true);
			$this->showModel($table, $id);
			$this->jquery->execAtLast("$(\"tr[data-ajax='" . $id . "']\").click();");
			echo $this->jquery->compile();
		}
	}

	protected function _showModel($model, $id = null) {
		$_SESSION["model"] = $model;
		$totalCount = 0;
		$datas = $this->getInstances($model, $totalCount, 1, $id);
		$this->formModal = ($this->_getModelViewer()->isModal($datas, $model)) ? "modal" : "no";
		return $this->_getModelViewer()->getModelDataTable($datas, $model, $totalCount, $this->activePage);
	}

	protected function getInstances($model, &$totalCount, $page = 1, $id = null) {
		$this->activePage = $page;
		$adminDatas = $this->_getAdminData();
		$totalCount = DAO::count($model, $adminDatas->_getInstancesFilter($model));
		$recordsPerPage = $this->_getModelViewer()->recordsPerPage($model, $totalCount);
		if (is_numeric($recordsPerPage)) {
			if (isset($id)) {
				$rownum = DAO::getRownum($model, $id);
				$this->activePage = Pagination::getPageOfRow($rownum, $recordsPerPage);
			}
			return DAO::paginate($model, $this->activePage, $recordsPerPage, $adminDatas->_getInstancesFilter($model), false);
		}
		return DAO::getAll($model, "", false);
	}

	protected function search($model, $search) {
		$fields = $this->_getAdminData()->getSearchFieldNames($model);
		return CRUDHelper::search($model, $search, $fields);
	}

	public function refresh_() {
		$model = $_POST["_model"];
		if (isset($_POST["s"])) {
			$instances = $this->search($model, $_POST["s"]);
		} else {
			$instances = $this->getInstances($model, $totalCount, URequest::post("p", 1));
		}
		if (! isset($totalCount)) {
			$totalCount = DAO::count($model, $this->_getAdminData()->_getInstancesFilter($model));
		}
		$recordsPerPage = $this->_getModelViewer()->recordsPerPage($model, $totalCount);
		if (isset($recordsPerPage)) {
			UResponse::asJSON();
			$responseFormatter = new ResponseFormatter();
			print_r($responseFormatter->getJSONDatas($instances));
		} else {
			$this->formModal = ($this->_getModelViewer()->isModal($instances, $model)) ? "modal" : "no";
			$compo = $this->_getModelViewer()
				->getModelDataTable($instances, $model, $totalCount)
				->refresh([
				"tbody"
			]);
			$this->jquery->execAtLast('$("#search-query-content").html("' . $_POST["s"] . '");$("#search-query").show();$("#table-details").html("");');
			$this->jquery->renderView("@admin/main/elements.html", [
				"compo" => $compo
			]);
		}
	}

	protected function _edit($instance, $modal = "no") {
		$_SESSION["instance"] = $instance;
		$modal = ($modal == "modal");
		$formName = "frmEdit-" . UString::cleanAttribute(get_class($instance));
		$form = $this->_getModelViewer()->getForm($formName, $instance);
		$this->jquery->click("#action-modal-" . $formName . "-0", "$('#" . $formName . "').form('submit');", false);
		if (! $modal) {
			$this->jquery->click("#bt-cancel", "$('#form-container').transition('drop');");
			$this->jquery->compile($this->view);
			$this->loadView($this->_getFiles()
				->getViewEditTable(), [
				"modal" => $modal,
				"frmEditName" => $formName
			]);
		} else {
			$this->jquery->exec("$('#modal-" . $formName . "').modal('show');", true);
			$form = $form->asModal(\get_class($instance));
			$form->setActions([
				"Okay_",
				"Cancel"
			]);
			$btOkay = $form->getAction(0);
			$btOkay->addClass("green")->setValue("Validate modifications");
			$form->onHidden("$('#modal-" . $formName . "').remove();");
			echo $form->compile($this->jquery, $this->view);
			echo $this->jquery->compile($this->view);
		}
	}

	public function edit($modal = "no", $ids = "") {
		$instance = $this->getModelInstance($ids, false);
		$instance->_new = false;
		$this->_edit($instance, $modal);
	}

	public function newModel($modal = "no") {
		$model = $_SESSION["model"];
		$instance = new $model();
		$instance->_new = true;
		$this->_edit($instance, $modal);
	}

	public function update() {
		$message = new CRUDMessage("Modifications were successfully saved", "Updating");
		$instance = @$_SESSION["instance"];
		$isNew = $instance->_new;
		$updated = CRUDHelper::update($instance, $_POST);
		if ($updated) {
			$pk = OrmUtils::getFirstKeyValue($instance);
			$message->setType("success")->setIcon("check circle outline");
			if ($isNew) {
				$this->jquery->get($this->_getFiles()
					->getAdminBaseRoute() . $this->_getFiles()
					->getRouteRefreshTable() . "/" . $pk, "#lv", [
					"jqueryDone" => "replaceWith"
				]);
			} else {
				if (DAO::$useTransformers) {
					TransformersManager::transformInstance($instance, 'toView');
				}
				$this->jquery->setJsonToElement(OrmUtils::objectAsJSON($instance));
			}
		} else {
			$message->setMessage("An error has occurred. Can not save changes.")
				->setType("error")
				->setIcon("warning circle");
		}
		echo $this->_showSimpleMessage($message, "updateMsg", true);
		echo $this->jquery->compile($this->view);
	}

	private function getModelInstance($ids, $transform = true) {
		$model = $_SESSION['model'];
		$ids = \explode("_", $ids);
		DAO::$useTransformers = $transform;
		$instance = DAO::getById($model, $ids, true);
		if (isset($instance)) {
			return $instance;
		}
		echo $this->showSimpleMessage("This object does not exist!", "warning", "Get object", "warning circle");
		echo $this->jquery->compile($this->view);
		exit(1);
	}

	public function delete($ids) {
		$instance = $this->getModelInstance($ids);
		if (method_exists($instance, "__toString"))
			$instanceString = $instance . "";
		else
			$instanceString = get_class($instance);
		if (sizeof($_POST) > 0) {
			if (DAO::remove($instance)) {
				$message = $this->showSimpleMessage("Deletion of `<b>" . $instanceString . "</b>`", "info", "Deletion", "info", null, null, null, true);
				$this->jquery->exec("$('tr[data-ajax={$ids}]').remove();", true);
			} else {
				$message = $this->showSimpleMessage("Can not delete `" . $instanceString . "`", "warning", "Error", "warning");
			}
		} else {
			$message = $this->showConfMessage("Do you confirm the deletion of `<b>" . $instanceString . "</b>`?", "error", "Remove confirmation", "question circle", $this->_getFiles()
				->getAdminBaseRoute() . "/delete/{$ids}", "#table-messages", $ids);
		}
		echo $message;
		echo $this->jquery->compile($this->view);
	}

	private function getFKMethods($model) {
		$reflection = new \ReflectionClass($model);
		$publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
		$result = [];
		foreach ($publicMethods as $method) {
			$methodName = $method->getName();
			if (JString::startswith($methodName, "get")) {
				$attributeName = lcfirst(JString::replaceAtFirst($methodName, "get", ""));
				if (! property_exists($model, $attributeName))
					$result[] = $methodName;
			}
		}
		return $result;
	}

	public function _modelDatabase($hasHeader = true, $update = false, $databaseOffset = 'default') {
		$semantic = $this->jquery->semantic();
		if ($update !== false) {
			$this->config['activeDb'] = $databaseOffset;
			$this->saveConfig();
		}
		if (($hasHeader = UString::isBooleanTrue($hasHeader))) {
			$stepper = $this->_getModelsStepper();
		}
		if ($this->_isModelsCompleted() || $hasHeader !== true) {
			$config = Startup::getConfig();
			try {
				$models = CacheManager::getModels($config, true, $databaseOffset);
				$menu = $semantic->htmlMenu("menuDbs");
				$menu->setVertical()->setInverted();
				foreach ($models as $model) {
					$count = DAO::count($model);
					$item = $menu->addItem(ClassUtils::getClassSimpleName($model));
					$item->addLabel($count);
					$tbl = OrmUtils::getTableName($model);
					$item->setProperty("data-ajax", $tbl);
					$item->setProperty("data-model", str_replace("\\", ".", $model));
				}
				$menu->getOnClick($this->_getFiles()
					->getAdminBaseRoute() . "/showModel", "#divTable", [
					"attr" => "data-model",
					"historize" => true
				]);
				$menu->onClick("$('.ui.label.left.pointing.teal').removeClass('left pointing teal');$(this).find('.ui.label').addClass('left pointing teal');");
			} catch (\Exception $e) {
				throw $e;
				$this->showSimpleMessage("Models cache is not created!&nbsp;", "error", "Exception", "warning circle", null, "errorMsg");
			}
			$this->_checkModelsUpdates($config, false);

			$this->jquery->compile($this->view);
			$this->loadView($this->_getFiles()
				->getViewDataIndex(), [
				'activeDb' => $databaseOffset
			]);
		} else {
			echo $stepper;
			echo "<div id='models-main'>";
			echo $this->jquery->semantic()->getHtmlComponent('opMessage');
			$this->_loadModelStep();
			echo "</div>";
		}
	}

	public function showDetail($ids) {
		$instance = $this->getModelInstance($ids);
		$viewer = $this->_getModelViewer();
		$hasElements = false;
		$model = $_SESSION['model'];
		$fkInstances = CRUDHelper::getFKIntances($instance, $model, false);
		$semantic = $this->jquery->semantic();
		$grid = $semantic->htmlGrid("detail");
		if (sizeof($fkInstances) > 0) {
			$wide = intval(16 / sizeof($fkInstances));
			if ($wide < 4)
				$wide = 4;
			foreach ($fkInstances as $member => $fkInstanceArray) {
				$element = $viewer->getFkMemberElementDetails($member, $fkInstanceArray["objectFK"], $fkInstanceArray["fkClass"], $fkInstanceArray["fkTable"]);
				if (isset($element)) {
					$grid->addCol($wide)->setContent($element);
					$hasElements = true;
				}
			}
			if ($hasElements)
				echo $grid;
			$this->jquery->getOnClick(".showTable", $this->_getFiles()
				->getAdminBaseRoute() . "/showModelClick", "#divTable", [
				"attr" => "data-ajax",
				"ajaxTransition" => "random"
			]);
			echo $this->jquery->compile($this->view);
		}
	}

	protected function getModelsNS() {
		return Startup::getConfig()["mvcNS"]["models"];
	}

	private function _getCks($array) {
		$result = [];
		foreach ($array as $dataAjax => $caption) {
			$result[] = $this->_getCk($caption, $dataAjax);
		}
		return $result;
	}

	private function _getCk($caption, $dataAjax) {
		$ck = new HtmlCheckbox("ck-" . $dataAjax, $caption, "1");
		$ck->setProperty("name", "ck[]");
		$ck->setProperty("data-ajax", $dataAjax);
		return $ck;
	}

	public function editMember($member) {
		$ids = URequest::post("id");
		$td = URequest::post("td");
		$part = URequest::post("part");
		$instance = $this->getModelInstance($ids, false);
		$_SESSION["instance"] = $instance;
		$_SESSION["model"] = get_class($instance);
		$instance->_new = false;
		$form = $this->_getModelViewer()->getMemberForm("frm-member-" . $member, $instance, $member, $td, $part);
		$form->setLibraryId("_compo_");
		$this->jquery->renderView("@admin/main/component.html");
	}

	public function updateMember($member, $callback = false) {
		$instance = @$_SESSION["instance"];
		$model = $_SESSION['model'];
		$updated = CRUDHelper::update($instance, $_POST);
		if ($updated) {
			if ($callback === false) {
				$dt = $this->_getModelViewer()->getModelDataTable([
					$instance
				], $model, 1);
				$dt->compile();
				$value = $dt->getFieldValue($member);
				if (DAO::$useTransformers) {
					$value = TransformersManager::applyTransformer($instance, $member, $value, 'toView');
				}
				echo new HtmlContentOnly($value);
				$toast = new Toast();
				$toast->setMessage('Data updated');
				echo '<script>' . $toast->getScript() . '</script>';
			} else {
				if (method_exists($this, $callback)) {
					$this->$callback($member, $instance);
				} else {
					throw new \Exception("The method `" . $callback . "` does not exists in " . get_class());
				}
			}
		} else {
			UResponse::setResponseCode(404);
		}
	}
}
