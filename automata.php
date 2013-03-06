<?
class Automata{
  const ERROR_STATE = -1; // estado de error
  const LITERAL_TOKEN = 0; // token literal
  const DQSTRING_TOKEN = 1; // token cadena entre comillas dobles
  const SQSTRING_TOKEN = 2; // token cadena entre comillas simples
  const RANGE_TOKEN = 3; // token rango
  const REGEXP_TOKEN = 4; // token expresión regular
  const NUMERIC_TOKEN = 4; // token numérico
  protected $ajustes=array()
    ,$estados=array() // estados durante el funcionamiento del autómata
    ,$nuevosEstados=array() // nuevos estados durante el funcionamiento del autómata
    ,$finales=array() // tabla de estados finales
    ,$variables=array() // tabla de variables internas
    ,$final=false
    ,$tokens=array()
    ,$utf8=true
    ,$inputFile=''
    ,$tokenfunc=null
    ,$pregPrefix='/^'
    ,$pregSuffix='/si'
    ,$res=null
    ,$pos=0
    ,$oldPosition=0
    ,$length=0
    ,$partial=''
    ,$text=''
  ;
  public function __set($name,$value){
    if(isset($this->ajustes[$name]))$this->ajustes[$name]=$value; // sólo se sobreescriben las propiedades válidas
  }
  public function __get($name){
    return isset($this->ajustes[$name])?$this->ajustes[$name]:null;
  }
  
  private function len($subject){
    return $this->ajustes['utf8']?mb_strlen($subject):strlen($subject);
  }
  private function substr($subject,$start,$len=null){
    return is_null($len)?($this->ajustes['utf8']?mb_substr($subject,$start):strlen($subject,$start)):($this->ajustes['utf8']?mb_substr($subject,$start,$len):strlen($subject,$start,$len));
  }
  private function charAt($subject,$pos){
    return $this->substr($subject,$pos,1);
  }
  private function setPos($newPos){
    $this->oldPosition=$this->pos;
    $this->pos=$newPos;
    $this->partial=$this->substr($this->text,$newPos);
  }
  private function swap(&$a,&$b){
    $t=$a;
    $a=$b;
    $b=$t;
  }
  
  private function getNextCharAsToken($noAdvance=false){
    $result=null; // EOF
    if($this->pos<$this->length){
      $result=$this->charAt($this->pos);
      if(!$noAdvance)$this->setPos($this->pos+1); // siempre avanzamos 1, aunque hayamos leído más de 1 byte (UTF8)
    }
    return $result;
  }
  private function getNextToken($noAdvance=false){
    $result=''; // No token
    if($this->pos<$this->length){
      $len=0; // longitud del token encontrado
      foreach($this->tokens as $token=>$regex){
        $res=null; // resultados parciales
        if(preg_match($this->pregPrefix.$regex.$this->pregSuffix,$this->partial,$res)){ // ver si hay un token reconocido
          $newlen=$this->len($res[0]);
          if($newlen>$len){ // favorecer el más largo
            $len=$newlen;
            $result=$token;
          }
        }
      }
      if(!$len)$result=$this->getNextCharAsToken(); // si no se encuentra ningún token, tomar el siguiente caracter como token
      else if(!$noAdvance)$this->setPos($this->pos+$len); // si se encontró, saltarlo completamente
    }else $result=null; // EOF
    return $result;
  }
  
  private function doEvent($state,$event){
    $fname=$state.'_'.$event;
    if(function_exists($fname))$fname();
  }
  
  protected function reset(){
    $this->text=$this->partial='';
    $this->pos=$this->len=$this->oldPosition=0;
    $this->res=null;
    $this->estados=array();
    $this->final=false;
  }
  private function initialize(){
    $this->utf8=&$this->ajustes['utf8']; // menos escribir para hacer esta comprobación
    $this->tokenfunc=$this->ajustes['char_mode']?(!is_null($this->ajustes['token_func'])?$this->ajustes['token_func']:null):null; // función de lectura de caracteres
    if(is_null($this->tokenfunc))$this->tokenfunc=array($this,'getNextToken');
    $this->pregSuffix='/'.($this->ajustes['ignore_case']?'i':'').($this->ajustes['single_lines']?'m':'s').($this->ajustes['utf8']?'u':''); // ajustar las expresiones regulares
    $this->pregPrefix='/^'.($this->ajustes['ignore_whitespace']?'\s*':''); // si se ignoran los espacios en blanco, son arbitrarios al comienzo de un token
    $this->tokens=array();
  }  
  private function setDefaultOptions(){
    $this->ajustes=array(
      'ignore_case' => true, // por defecto, no se distingue entre mayúsculas y minúsculas
      'char_mode' => true, // por defecto, la entrada son caracteres; si esto fuera "false", la entrada sería una función que devuelve enteros (tokens)
      'token_func' => null, // por defecto, esta es la función de lectura de tokens; debe devolver -1 para indicar EOF
      'utf8' => true, // por defecto, trabajamos con UTF8; en caso contrario, trabajamos con ANSI (1 byte por caracter)
      'ignore_whitespace' => true, // por defecto, ignoramos los espacios en blanco al procesar los tokens
      'single_lines' => false, // por defecto, las nuevas líneas no se consideran separadores de nada
    );
    $this->initialize();
  }
  private function checkTokenType($token){
    $type=self::LITERAL_TOKEN;
    if($token[0]=='"'&&$token[strlen($token)-1]=='"'){ // cadena entre comillas dobles
      $type=self::DQSTRING_TOKEN;
    }elseif($token[0]=="'"&&$token[strlen($token)-1]=="'"){ // cadena entre comillas simples
      $type=self::SQSTRING_TOKEN;
    }elseif(preg_match('/([+-]?\d+)\s*\.\.\s*([-+]?\d+)/',$token)){ // rango numérico
      $type=self::RANGE_TOKEN;
    }
    return $type;
  }
  
  
  public function __construct($fname=''){
    $this->setDefaultOptions();
    if($fname&&@is_file($fname)&&@is_readable($fname)){ // si es un archivo correcto
      $this->inputFile=$fname; // guardar el nombre
      $this->text=file_get_contents($fname); // y leerlo completo
      $this->pos=$this->oldPosition=0;
      $this->length=strlen($this->text);
      $this->partial='';
      $this->res=null;
    }
  }
  public function setOptions(array $options){
    foreach($options as $optname=>$value){
      if($optname=='dummy')break; // final de opciones
      if(($optname=='token_func'&&is_callable($value))||$optname!='token_func')$this->$optname=$value;
    }
    $this->initialize(); // reinicializar
  }
  public function addTokens(array $tokens){
    foreach($tokens as $token=>$regex){
      if($token=='dummy')break; // final de tokens
      $this->tokens[$token]=$regex;
    }
  }
  public function isEOF(){
    return $this->pos==$this->length;
  }
  public function isErrorState(){
    return in_array(self::ERROR_STATE,$this->estados);
  }
  public function isEndStateReached(){
    // return $this->intersection($this->estados,$this->finales)!=null;
    return $this->final;
  }
  public function atEnd(){
    $this->final=true; // nos dicen que este es el final, y nos lo creemos
  }
  public function getCurrentStates(){
    return $this->estados;
  }
  public function isInState($state){
    return in_array($state,$this->estados);
  }
  public function doEnter($state){
    $this->doEvent($state,'ENTER');
  }
  public function doRepeat($state){
    $this->doEvent($state,'REPEAT');
  }
  public function doExit($state){
    $this->doEvent($state,'EXIT');
  }
  public function gotoStates($states){
    if(preg_match('/((?:0|\d+|\&\w+)(?:\s*,\s*(?:0|\d+|\&\w+))*)/si',$states)){
      $states=preg_split('/\s*,\s*/si',$states,-1,PREG_SPLIT_NO_EMPTY);
      $this->estados=$states; // ya estamos en estos
    }
  }
  public function gotoState($state){
    $this->estados=array($state);
  }
  public function getVariable($varName){
    return isset($this->variables[$varName])?$this->variables[$varName]:null;
  }
  public function getRange($rangeString){
    $result=array();
    if(preg_match('/^([-+]?\d+)\s*\.\.\s*([-+]?\d+)$/s',$rangeString,$res)){
      $result=array($res[1],$res[2]);
    }
    return $result;
  }
  public function tokenMatch($token){
    $nextToken=$this->getNextToken(true); // leer el siguiente token sin avanzar
    $ok=$nextToken==$token; // ver si coincide
    if($ok)$this->getNextToken(); // avanzar si coincide
    return $ok;
  }
  public function regexpMatch($regexp){
    $ok=false;
    if(preg_match($this->pregPrefix.$regex.$this->pregSuffix,$this->partial,$res)){
      $ok=true;
      $this->setPos($this->pos+$this->len($res[0])); // avanzar si coincide
    }
    return $ok;
  }
  public function stringMatch($string,$delimiter){
    $ok=false;
    if(!$delimiter||($delimiter&&$string[0]==$delimiter&&$string[strlen($string)-1]==$delimiter)){
      if($delimiter)$string=substr($string,1,-1); // quitar las comillas de alrededor
      if($this->ajustes['ignore_whitespace']&&preg_match($this->pregPrefix.'\s+'.$this->pregSuffix,$this->partial,$res)){ // si vamos a ignorar espacios
        $this->setPos($this->pos+$this->len($res[0])); // saltar espacios
      }
      $slen=$this->len($string);
      $ok=$this->len($this->partial)>$slen&&$this->substr($this->partial,0,$slen)==$string; // si coincide por completo
      if($ok)$this->setPos($this->pos+$slen); // avanzar si coincide
    }
    return $ok;
  }
  public function numericMatch($number){
    $ok=false;
    $ok=$this->stringMatch($number.'',''); // cadena SIN delimitadores
    return $ok;
  }
  public function rangeMatch($min,$max){
    $ok=false;
    if($min>$max)$this->swap($min,$max);
    if(preg_match($this->pregPrefix.'[-+]?(?:\d+(?:\.\d*)?|0?\.\d+)'.$this->pregSuffix,$this->partial,$res)){ // detectar un número cualquiera
      $val=(double)$res[0]; // convertir
      $ok=$min<=$val&&$val<=$max; // ambos inclusive
      if($ok)$this->setPos($this->pos+$this->len($res[0])); // avanzar si coincide
    }
    return $ok;
  }
  public function variableMatch($varname){
    $ok=false;
    if(isset($this->variables[$varname])){
      $value=$this->variables[$varname]; // tomar el valor
      $ok=$this->stringMatch($value,''); // cadena SIN delimitadores
    }
    return $ok;
  }
  public function multiTokenMatch(array $tokens,$serial){
    $ok=false;
    $savePos=$this->pos;
    foreach($tokens as $token){
      list($type,$token)=$token;
      switch($type){
        case'token':
          $ok=$this->tokenMatch($token); // comprobar el token apropiado
        break;
        case'regexp':
          $ok=$this->regexpMatch($token); // comprobar esta expresión regular
        break;
        case'string':
          $ok=$this->stringMatch($token,$token[0]); // comprobar cadena, usando el primer caracter como delimitador
        break;
        case'numeric':
          $ok=$this->numericMatch($token); // comprobar número
        break;
        case'range':
          list($min,$max)=$token; // el rango a comprobar
          $ok=$this->rangeMatch($min,$max); // comprobar rango
        break;
        case'variable':
          $ok=$this->variableMatch($token); // comprobar cadena
        break;
        case'literal':
          $ok=$this->stringMatch($token,''); // cadena sin delimitadores (literal)
        break;
        default: // transiciones especiales
          $ok=false; // siempre fallan estas (en realidad, no se permiten)
        break;
      }
      if(!$ok&&$serial){ // al primer fallo, si estamos en serie
        $this->pos=$savePos; // retroceder al principio del todo
        break; // y terminar
      }elseif($ok&&!$serial){ // al primer acierto, si estamos en paralelo
        break; // terminar simplemente
      }
    }
    return $ok;
  }
  public function error(){
    $this->estados[]=self::ERROR_STATE;
  }
  
  public function freeze(){
    $tmpfile=preg_replace('/^(.*?)\.\w+$/i','.tmp',$this->fname); // archivo temporal
    $currentState=array('tokens'=>$this->tokens,'ajustes'=>$this->ajustes,'estados'=>$this->estados,'nuevosEstados'=>$this->nuevosEstados,
      'finales'=>$this->finales,'variables'=>$this->variables);
echo"Estado actual:<pre>".htmlspecialchars(print_r($currentState,true))."</pre>";
    file_put_contents($tmpfile,base64_encode(serialize($currentState))); // volcar el estado actual al archivo
  }
  public function unFreeze(){
    $tmpfile=preg_replace('/^(.*?)\.\w+$/i','.tmp',$this->fname); // archivo temporal
    if(file_exists($tmpfile)){
      $newState=unserialize(base64_decode(file_get_contents($tmpfile))); // releer los valores
echo"Nuevo estado:<pre>".htmlspecialchars(print_r($newState,true))."</pre>";
      $this->tokens=$newState['tokens'];
      $this->ajustes=$newState['ajustes'];
      $this->estados=$newState['estados'];
      $this->nuevosEstados=$newState['nuevosEstados'];
      $this->finales=$newState['finales'];
      $this->variables=$newState['variables'];
   }
  }
  public function getCurrentText(){
    return $this->text;
  }
  public function setCurrentText($text){
    $this->text=$text;
  }
  public function getCurrentPosition(){
    return $this->pos;
  }
  public function setCurrentPosition($pos){
    if(is_numeric($pos)&&$pos>=0&&$pos<$this->length)$this->pos=$pos; // si es posición correcta, asignarla
  }
  public function getCurrentStateStartingPosition(){
    return $this->oldPosition;
  }
}
?>