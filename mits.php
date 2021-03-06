<?php
//Singleton implementation of the MemoryManagerFactory
final class MemoryManagerFactory {
    /**
     * Call this method to get singleton
     *
     * @return MemoryManager
     */
    public static function Instance()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new MITSMemoryManager();
        }
        return $inst;
    }

    private function __construct() { }

    private function __clone() {}
}

class MITSMemoryManager extends MemoryManager {
  public $contexts;


  function __construct() {
    $this->contexts = array();
    $this->getContexts();
  }

  //This function returns a database connection
  function getConnection() {
    $user = "mits_user";
    $host = "localhost";
    $password = "mits";
    $database = "mits";
    $connection = mysqli_connect($host, $user, $password, $database);
    return $connection;
  }
  //This function loads the contexts in memory
  function getContexts() {
    $connection = $this->getConnection();
    //$query = "Select * from contexts Where date >= "
    $query = "Select * from contexts";
    $result = mysqli_query($connection, $query);
    while($data = mysqli_fetch_array($result)) {
      $this->contexts[$data['id_user']] = array("intention" => $data["intention"], "entity" => $data["entity"]);
    }
    return true;
  }

  function insertUser($user) {
    $connection = $this->getConnection();
    $query = "Select * from contexts where id_user = $user";
    $result = mysqli_query($connection, $query);
    if(mysqli_num_rows($result) == 0) {
      $date = date("Y-m-d h:i:s");
      $insert = "Insert into contexts Values(NULL, '$user', '', '', '$date')";
      mysqli_query($connection, $insert);
      return true;
    }
    return false;
  }

  //This function obtains user context
  function getUserContext($user) {
    $context = (isset($this->contexts[$user]))?$this->contexts[$user]:null;
    if($context == null) {
      $context = array("entity" => '', "intention" => '');
      $this->contexts[$user] = $context;
    }
    return $context;
  }

  //This fucntion updates the context for a user
  function updateUserContext($user, $context) {
    $this->insertUser($user);
    $connection = $this->getConnection();
    $intention = $context["intention"];
    $entity = $context["entity"];
    $date = date("Y-m-d h:i:s");
    $query = "Update contexts set intention='$intention', entity='$entity', date='$date' Where id_user = '$user'";
    mysqli_query($connection, $query);
    $this->contexts[$user] = $context;
  }

  //This function adds an interaction
  function addInteraction($user, $query, $response) {
    $connection = $this->getConnection();
    $date = date("Y-m-d h:i:s");
    $insert = "Insert into interactions Values(NULL, '$user', '$query', '$response', '$date')";
    mysqli_query($connection, $insert);
    return true;
  }
}

//EntityExtractor for the MITS system
class MITSEntityExtractor extends EntityExtractor {

  //MITSEntityExtractor constructor
  function __construct() {
    $this->entites = array();
    //We establish a connection to the domain knowledge to get the entities
    $domain = new MoodleToolsDomainKnowledge();
    $names = $domain->getEntitesNames();
    foreach ($names as $name) {
      $e =  new Entity();
      $e->type = 'String';
      $e->name = 'Moodle Tool';
      $e->instance = $name;
      $this->entities[] = $e;
    }
  }

  //Function to extract entities for the query
  public function extractEntities($query) {

  }
}

class MITSController extends ChatBotController {

  public function processQuery($query_r, $user) {
    $mm = new MITSMemoryManager();
    $context = $mm->getUserContext($user);
    $query = $this->constructQuery($query_r);
    //var_dump($query);
    $update = true;

    if(count($query->fields) == 0) {
      if($context["entity"] == "")
        $query->addField('nombre', 'CONVERSATION');
      else
        $query->addField('nombre', $context["entity"]);
    } else {
      $context["entity"] = $query->getField("nombre");
    }

    if($query->get_property == null) {
      if($context["intention"] != "")
        $query->get_property = $context["intention"];
    } else {
      $context["intention"] = $query->get_property;
    }

    if($query->hasField('nombre', 'CONVERSATION'))
      $update = false;

    if($update)
      $mm->updateUserContext($user, $context);

    $driver = new XmlDriver();
    $data = $driver->runQuery($query);
    if($data == null || $data == '') {
      $query = $this->constructQuery($query_r . 'conversation');
      $data = $driver->runQuery($query);
      if($data == null || $data == '')
        $data = "¿Puedes reformular la pregunta?";
    }


    //Update the interactions
    $mm->addInteraction($user, $query->raw_data, $data);

    return json_encode(['respuesta'=> $data]);
  }

  public function constructQuery($raw) {
    $domain = new MoodleToolsDomainKnowledge();
    return $domain->generateQuery($raw);
  }
}

class MITSUserProfiler extends UserProfiler {
  public $tools;
  public $profiles;

  function __construct() {
    $this->initialize();
  }

  function initialize() {
    $this->tools = array();
    for($i = 0; $i < 10; $i++) {
      $this->tools[] = array(20, 40, 60, 80);
    }

    //var_dump($this->tools);

    $this->profiles = array();
    $this->profiles[] = array(5, 5, 5, 1, 1, 1, 1, 1, 1, 1);
    $this->profiles[] = array(1, 1, 1, 5, 5, 5, 5, 1, 1, 1);
    $this->profiles[] = array(1, 1, 1, 1, 1, 1, 1, 5, 5, 5);

    //var_dump($this->profiles);
  }

  //This function obtains the name of the tool
  function getTool($index) {
    $names = array('conversacion', 'base de datos', 'chat', 'eleccion', 'encuesta', 'examen', 'externa',
    'foro', 'glosario', 'leccion', 'scorm', 'retroalimentacion', 'taller', 'tarea', 'wiki');
    return $names[$index];
  }

  function decideUserProfile($user) {
    //$this->initialize();
    $user_data = array();
    $l = count($this->tools[0]);
    $t = count($this->tools);
    for($i = 0; $i < $t; $i++) {
      $level = 1;
      $use = $this->decideLevelForTool($user, $i);
      //echo "Level of use $use <br />";
      for($j=0; $j < $l; $j++) {
        $lev = $this->tools[$i][$j];
        //echo "Checking level against $lev <br />";
        if($use > $this->tools[$i][$j])
          $level = $j + 2;
      }
      //echo "Level selected $level <br />";
      $user_data[] = $level;
    }

    /*echo 'Checking use for <br /><br />';
    var_dump($user_data);
    echo '<br /><br />';*/
    $index = null;
    $similarity = 0;
    $pn = count($this->profiles);
    for($i = 0; $i < $pn; $i++) {
      //We compute s as the soft cosine smilarity between profile i and user data
      $b = $this->profiles[$i];
      //var_dump($b);
      $s = $this->computeSimilarity($user_data, $b, $l);

      //echo "Cosine similarity between a and b $s <br />";
      if($s > $similarity) {
        $index = $i;
        $similarity = $s;
      }
    }

    return array('user_data' => $user_data, 'profile' => $index, 'similarity' => $similarity);
  }

  function decideUserProfileFromData($user_data) {

    $l = count($this->tools[0]);
    $t = count($this->tools);

    $index = null;
    $similarity = 0;
    $pn = count($this->profiles);
    for($i = 0; $i < $pn; $i++) {
      //We compute s as the soft cosine smilarity between profile i and user data
      $b = $this->profiles[$i];
      //$s = $this->computeSoftCosineSimilarity($user_data, $b, $l);
      $s = $this->computeCosineSimilarity($user_data, $b, $l);
      //$s = $this->computeSimilarity($user_data, $b, $l);

      if($s > $similarity) {
        $index = $i;
        $similarity = $s;
      }
    }

    return array('user_data' => $user_data, 'profile' => $index, 'similarity' => $similarity);
  }

  //This function computes the soft cosine similarity between a and b vectors
  function computeSoftCosineSimilarity($a, $b, $scale = 5) {
    $similarity = 0;
    $sum = 0;
    $suma = 0;
    $sumb = 0;
    $n = count($a);
    for($i = 0; $i < $n; $i++)
      for($j = 0; $j < $n; $j++) {
        $sij = 1 - (abs($a[$i] - $b[$j])/$scale);
        $sum += $sij * $a[$i] * $b[$j];
        $suma += $sij * $a[$i] * $a[$j];
        $sumb += $sij * $b[$i] * $b[$j];
      }
    $similarity = $sum / (sqrt($suma) * sqrt($sumb));
    return $similarity;
  }

  //This function computes the soft cosine similarity between a and b vectors
  function computeCosineSimilarity($a, $b, $scale = 5) {
    $similarity = 0;
    $sum = 0;
    $suma = 0;
    $sumb = 0;
    $n = count($a);
    for($i = 0; $i < $n; $i++) {
      $sum += $a[$i] * $b[$i];
      $suma += $a[$i] * $a[$i];
      $sumb += $b[$i] * $b[$i];
    }

    $similarity = $sum / (sqrt($suma) * sqrt($sumb));
    return $similarity;
  }

  //Diferencia normalizada de los vectores a y b
  function computeSimilarity($a, $b, $scale = 5) {
    $similarity = 0;
    $sum = 0;
    $n = count($a);
    for($i = 0; $i < $n; $i++) {
      $sum += 1 - (abs($a[$i] - $b[$i]))/$scale;
    }

    $similarity = $sum / ($n * 1.0);
    return $similarity;
  }

  //This function decides which level to apply to the tool
  function decideLevelForTool($user, $index) {
    $data = $this->getUseDataForTool($user, $index);
    /*echo 'Checking use for <br /><br />';
    var_dump($data);
    echo '<br /><br />';*/
    $mu = 0;
    $sigma = 0;
    $m = count($data);
    //Compute standard mean for the tool
    foreach ($data as $val)
      $mu += $val;
    $mu /= $m;
    //Compute standar deviation for the tool
    foreach ($data as $val)
      $sigma += sqrt(pow(($val - $mu), 2));
    $sigma /= $m;

    //Exclude the 2 * sigma differences
    $level_of_use = 0;
    $counter = 0;
    foreach ($data as $val) {
      if(sqrt(pow(($val - $mu), 2)) < 2*$sigma) {
        $level_of_use += $val;
        $counter += 1.0;
        //echo "Including $val <br />";
      }
    }

    //echo "Mean $mu, Sigma $sigma  Level of use $level_of_use <br />";

    if($counter > 0)
      return $level_of_use/$counter;
    return 0;
  }

  //This function gets the use data of a tool for all the courses of a user
  function getUseDataForTool($user, $index, $n=7) {
    $use = array();
    for($i=0; $i<$n; $i++)
      $use[] = rand(1, 100);
    return $use;
  }
}

//This function implements the tutor
class MITSTutor extends Tutor {
  var $profiler;

  function __construct() {
    $this->profiler = new MITSUserProfiler();
  }

  public  function decideFedBack($user) {
    $profile = $this->profiler->decideUserProfile($user);
    $data = $profile['user_data'];
    $selected_profile = $profile['profile'];
    $index = 0;
    $difference = max($data[0] - $this->profiler->profiles[$selected_profile][0], 0);
    for($i=0; $i < count($data); $i++) {
      $diff = max($data[$i] - $this->profiler->profiles[$selected_profile][$i], 0);
      if($diff > $difference) {
        $difference = $diff;
        $index = $i;
      }
    }
    return $this->attendRequest('TQue es ' . $this->profiler->getTool($index), $user);
  }

  //This function attends a request from a user
  public function attendRequest($request, $user) {
    //echo "Answering to $request <br />";
    $controller = new MITSController();
    return $controller->processQuery($request, $user);
  }
}
