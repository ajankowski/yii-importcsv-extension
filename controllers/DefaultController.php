<?php
/**
 * @file
 * Controller class for importing csv files.
 */

class DefaultController extends Controller
{

  public $colsArray = array();

  /**
   * Import form.
   */
  public function actionIndex() {

    $delimiter = ";";
    $textDelimiter = '"';

    // Add css and js.
    Yii::app()->clientScript->registerCssFile(
      Yii::app()->assetManager->publish(
        Yii::getPathOfAlias('application.modules.importcsv.assets') . '/styles.css'
      )
    );

    Yii::app()->clientScript->registerScriptFile(
      Yii::app()->assetManager->publish(
        Yii::getPathOfAlias('application.modules.importcsv.assets') . '/ajaxupload.js'
      )
    );

    Yii::app()->clientScript->registerScript('uploadActionPath', 'var uploadActionPath="' . $this->createUrl('default/upload') . '"', CClientScript::POS_BEGIN);

    Yii::app()->clientScript->registerScriptFile(
      Yii::app()->assetManager->publish(
        Yii::getPathOfAlias('application.modules.importcsv.assets') . '/download.js'
      )
    );

    // Get all the tables from the database.
    $tables = $this->getTables();
    $tablesLength = sizeof($tables);
    $tablesArray = array();
    // Set up the tablesArray so it can be used in a form.
    for ($i = 0; $i < $tablesLength; $i++) {
      $tablesArray[$tables[$i]] = $tables[$i];
    }

    if (Yii::app()->request->isAjaxRequest) {
      if ($_POST['thirdStep'] != 1) {
        // SECOND STEP.
        $delimiter = str_replace('&quot;', '"', str_replace("&#039;", "'", CHtml::encode(trim($_POST['delimiter']))));
        $textDelimiter = str_replace('&quot;', '"', str_replace("&#039;", "'", CHtml::encode(trim($_POST['textDelimiter']))));
        $table = CHtml::encode($_POST['table']);

        if ($_POST['delimiter'] == '') {
          $error = 1;
          $csvFirstLine = array();
          $paramsArray = array();
        }
        else {
          // Get all columns from csv file.
          $error = 0;
          $uploaddir = Yii::app()->controller->module->path;
          $uploadfile = $uploaddir . basename($_POST['fileName']);
          $file = fopen($uploadfile, "r");
          $csvFirstLine = ($textDelimiter) ? fgetcsv($file, 0, $delimiter, $textDelimiter) : fgetcsv($file, 0, $delimiter);
          fclose($file);

          // Checking file with earlier imports.
          $paramsArray = $this->checkOldFile($uploadfile);
        }

        // Get all columns from selected table.
        $model = new ImportCsv;
        $tableColumns = $model->tableColumns($table);

        $this->layout = 'clear';
        $this->render('secondResult', array(
          'error' => $error,
          'tableColumns' => $tableColumns,
          'delimiter' => $delimiter,
          'textDelimiter' => $textDelimiter,
          'table' => $table,
          'fromCsv' => $csvFirstLine,
          'paramsArray' => $paramsArray,
        ));
      }
      else {

        // Third step.
        $delimiter = str_replace('&quot;', '"', str_replace("&#039;", "'", CHtml::encode(trim($_POST['thirdDelimiter']))));
        $textDelimiter = str_replace('&quot;', '"', str_replace("&#039;", "'", CHtml::encode(trim($_POST['thirdTextDelimiter']))));
        $table = CHtml::encode($_POST['thirdTable']);
        $uploadfile = CHtml::encode(trim($_POST['thirdFile']));
        $columns = $_POST['Columns'];
        $perRequest = CHtml::encode($_POST['perRequest']);
        $tableKey = CHtml::encode($_POST['Tablekey']);
        $csvKey = CHtml::encode($_POST['CSVkey']);
        $mode = CHtml::encode($_POST['Mode']);
        $insertArray = array();
        $error_array = array();

        if (array_sum($_POST['Columns']) > 0) {
          if ($_POST['perRequest'] != '') {
            if (is_numeric($_POST['perRequest'])) {
              if (($mode == 2 || $mode == 3) && ($tableKey == '' || $csvKey == '')) {
                $error = 4;
              }
              else {
                $error = 0;

                $overrwrite = Yii::app()->controller->module->importCsvOverwrite;

                // Check if a class extends the ImportCsv class. If it does
                // then call it otherwise just call the ImportCsv class.
                if (isset($overrwrite[$table])) {
                  $model = new $overrwrite[$table];
                }
                else {
                  $model = new ImportCsv;
                }

                $tableColumns = $model->tableColumns($table);

                // Select old rows from table.
                if ($mode == ImportCsv::MODE_INSERT_NEW || $mode ==  ImportCsv::MODE_INSERT_NEW_REPLACE_OLD) {
                  $model->oldItems = $model->selectRows($table, $tableKey);

                  $pathToSaveOldData = Yii::app()->controller->module->pathToSaveOldData;

                  if ($pathToSaveOldData) {
                    $this->saveOldDataToCSV($pathToSaveOldData, $model->selectRows($table, '*'));
                  }
                }

                $filecontent = file($uploadfile);
                $lengthFile = sizeof($filecontent);
                $insertCounter = 0;
                $stepsOk = 0;

                $model->table = $table;
                $model->columns = $columns;
                $model->tableColumns = $tableColumns;
                $model->lengthFile = $lengthFile;
                $model->perRequest = $perRequest;
                $model->csvKey = $csvKey;
                $model->tableKey = $tableKey;

                // Begin transaction.
                $transaction = Yii::app()->db->beginTransaction();
                try {
                  // Import to database.
                  for ($i = 0; $i < $lengthFile; $i++) {
                    if ($i != 0 && $filecontent[$i] != '') {
                      $csvLine = ($textDelimiter) ? str_getcsv($filecontent[$i], $delimiter, $textDelimiter) : str_getcsv($filecontent[$i], $delimiter);

                      $model->modifyCsvLine($csvLine);

                      // Mode 1. insert All.
                      if ($mode == ImportCsv::MODE_IMPORT_ALL) {
                        $model->insertAllIntoDatabase($csvLine, $i);
                      }

                      // Mode 2. Insert new.
                      if ($mode == ImportCsv::MODE_INSERT_NEW) {
                        $model->insertNewIntoDatabse($csvLine, $i);
                      }

                      // Mode 3. Insert new and replace old.
                      if ($mode == ImportCsv::MODE_INSERT_NEW_REPLACE_OLD) {
                        $model->insertNewReplaceOldIntoDatabse($csvLine, $i);
                      }
                    }
                  }
                  // If items weren't added because they were less than the
                  // request amount.
                  if ($model->insertCounter !== 0) {
                    $model->InsertAll($table, $model->insertArray, $columns, $tableColumns);
                  }

                  // commit transaction if not exception
                  $transaction->commit();
                }
                catch (Exception $e) { // exception in transaction
                  $transaction->rollBack();
                }

                // Save params in file.
                $this->saveInFile($table, $delimiter, $mode, $perRequest, $csvKey, $tableKey, $tableColumns, $columns, $uploadfile, $textDelimiter);
              }
            }
            else {
              $error = 3;
            }
          }
          else {
           $error = 2;
          }
        }
        else {
          $error = 1;
        }

        $this->layout = 'clear';
        $this->render('thirdResult', array(
          'error' => $error,
          'delimiter' => $delimiter,
          'textDelimiter' => $textDelimiter,
          'table' => $table,
          'uploadfile' => $uploadfile,
          'error_array' => $error_array,
        ));
      }

      Yii::app()->end();
    }
    else {
      // First loading.
      $this->render('index', array(
        'delimiter' => $delimiter,
        'textDelimiter' => $textDelimiter,
        'tablesArray' => $tablesArray,
      ));
    }
  }

  /**
   * File upload
   */
  public function actionUpload() {
    $uploaddir = Yii::app()->controller->module->path;
    $uploadfile = $uploaddir . basename($_FILES['myfile']['name']);

    $name_array = explode(".", $_FILES['myfile']['name']);
    $type = end($name_array);

    if ($type == "csv") {
      if (move_uploaded_file($_FILES['myfile']['tmp_name'], $uploadfile)) {
        $importError = 0;
      }
      else {
        $importError = 1;
      }
    }
    else {
      $importError = 2;
    }

    // Checking file with earlier imports.
    $paramsArray = $this->checkOldFile($uploadfile);
    $delimiterFromFile = $paramsArray['delimiter'];
    $textDelimiterFromFile = $paramsArray['textDelimiter'];
    $tableFromFile = $paramsArray['table'];

    // view rendering
    $this->layout = 'clear';
    $this->render('firstResult', array(
        'error' => $importError,
        'uploadfile' => $uploadfile,
        'delimiterFromFile' => $delimiterFromFile,
        'textDelimiterFromFile' => $textDelimiterFromFile,
        'tableFromFile' => $tableFromFile,
    ));
  }

  /**
   * Save import params in php file, for using in next imports.
   *
   * @param string $table
   *   Datbase table.
   * @param string $delimiter
   *   Csv delimiter.
   * @param string $textDelimiter
   *   Csv text delimiter.
   * @param integer $mode
   *   Import mode.
   * @param string $perRequest
   *   Items in one Insert moode
   * @param string $csvKey
   *   Key for comparing from csv file.
   * @param string $tableKey
   *   Key for compare from table.
   * @param array $tableColumns
   *   Array of table columns.
   * @param array $csvColumns
   *   Array of csv columns.
   * @param string $uploadfile
   *   Path to import file.
   */
  public function saveInFile($table, $delimiter, $mode, $perRequest, $csvKey, $tableKey, $tableColumns, $csvColumns, $uploadfile, $textDelimiter) {
    $columnsSize = sizeof($tableColumns);
    $columns = '';
    for ($i = 0; $i < $columnsSize; $i++) {
      $columns = ($csvColumns[$i] != "") ? $columns . '"' . $tableColumns[$i] . '"=>' . $csvColumns[$i] . ', ' : $columns . '"' . $tableColumns[$i] . '"=>"", ';
    }

    $delimiter = addslashes($delimiter);
    $textDelimiter = addslashes($textDelimiter);

    $arrayToFile = '<?php
      $paramsArray = array(
        "table"=>"' . $table . '",
        "delimiter"=>"' . $delimiter . '",
        "textDelimiter"=>"' . $textDelimiter . '",
        "mode"=>' . $mode . ',
        "perRequest"=>' . $perRequest . ',
        "csvKey"=>"' . $csvKey . '",
        "tableKey"=>"' . $tableKey . '",
        "columns"=>array(
          ' . $columns . '
        ),
      );
    ?>';

    $uploadfileArray = explode(".", $uploadfile);
    $uploadfileArray[sizeof($uploadfileArray) - 1] = "php";
    $uploadfileNew = implode(".", $uploadfileArray);

    $fileForWrite = fopen($uploadfileNew, "w+");
    fwrite($fileForWrite, $arrayToFile);
    fclose($fileForWrite);
  }

    /*
     * checking file with earlier imports
     *
     * $uploadfile - path to import file
     * @return array Old params from file
     *
     */

  /**
   * Checking fole with earlier imports.
   *
   * @param string  $uploadfile
   *   Path to import file.
   *
   * @return array $paramsArray
   *   Array old params from file.
   */
  public function checkOldFile($uploadfile) {
    $selectfileArray = explode(".", $uploadfile);
    $selectfileArray[sizeof($selectfileArray) - 1] = "php";
    $selectfileNew = implode(".", $selectfileArray);

    if (file_exists($selectfileNew)) {
      require_once($selectfileNew);
      $paramsArray['delimiter'] = stripslashes($paramsArray['delimiter']);
      $paramsArray['textDelimiter'] = stripslashes($paramsArray['textDelimiter']);
    }
    else {
      $paramsArray['delimiter'] = ";";
      $paramsArray['textDelimiter'] = '"';
      $paramsArray['table'] = "";
      $paramsArray['mode'] = "";
      $paramsArray['perRequest'] = "10";
      $paramsArray['csvKey'] = "";
      $paramsArray['tableKey'] = "";
      $paramsArray['columns'] = array();
    }

    return $paramsArray;
  }

  /**
   * Return array of tables
   *
   * @return array
   *   Array of table names.
   */
  private function getTables() {
    $allowedTables = Yii::app()->controller->module->allowedTables;
    if ($allowedTables) {
      return $allowedTables;
    }
    else {
      return Yii::app()->getDb()->getSchema()->getTableNames();;
    }
  }

  /**
   * Before you import new data save the old data to a csv file to have for
   * record.
   *
   * @param array $oldData
   *   Table data before import
   * @param $string $path
   *   Path to the csv import.
   *
   * @return boolean
   */
  public function saveOldDataToCSV($path, $oldData) {
    if (count($oldData) == 0) {
      return NULL;
    }

    $file = fopen($path . "/old_data_export_" . date('mdy-his') . '.csv', 'w');
    fputcsv($file, array_keys(reset($oldData)));

    foreach ($oldData as $row) {
      fputcsv($file, $row);
    }

    fclose($file);

    return TRUE;
  }
}
