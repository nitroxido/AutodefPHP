<?
error_reporting(E_ALL);
class CreadorAutomatas{
  const JS_IDENTIFIER = '(?:[_\$\p{L}])[_\d\$\p{L}]*'; // identificador JavaScript
  const TODO_TOKEN='(?:[-+]?\d+\s*\.\.\s*[-+]?\d+|\$[A-Za-z_]\w*\s*|(?:\"(?:\\.|[^\\\"])*?\")|(?:\'(?:\\.|[^\\\'])*?\')|\/(?:\\\\.|[^\r\n])*?\/|[A-Z]\w*|[€\$]|eof|error)';
  private $nombreAutomata='' // el nombre del autómata que se está procesando
    ,$codigoGlobal='' // el código global a incluir
    ,$ajustes=array() // la tabla de ajustes encontrados
    ,$tokens=array() // la tabla de tokens encontrados
    ,$transiciones=array() // la tabla (temporal) de transiciones encontradas en un estado dado
    ,$estados=array() // la tabla de estados del autómata
    ,$eventos=array() // la tabla de eventos del autómata
  ;
  private function preg_xquote($s,$delim=""){
    return preg_replace('/[#]/','\\\0',preg_quote($s,$delim?$delim[0]:"/"));
  }
  private function TablaLlena(&$array){
    return !is_null($array)&&is_array($array)&&count($array);
  }
  private function ConvierteTipo($val){
    $result=$val;
    switch(true){
      case preg_match('/^[-+]?(?:\d+(?:\.\d*)?|0?\.\d+)$/s',$val):
        $result=(double)$val;
      break;
      case preg_match('/^(true|false)$/s',$val):
        $result=$val=='true';
      break;
      case preg_match('/^((?:\'(?:\\\\.|[^\'])*\')|(?:\"(?:\\\\.|[^\"])*\"))$/s',$val):
        $result=substr($val,1,-1);
      break;
    }
    return $result;
  }
  private function LimpiaCodigo($text,$slc="//",$mlcs="/*",$mlce="*/",$slca=""){
    if(is_array($text))$text=implode('',$text); // convertir tabla en cadena
    $pslc=preg_quote($slc[0],'/'); // comienzo de comentario de una línea
    $pmlc=preg_quote($mlcs[0],'/'); // comienzo de comentario multilínea
    $pslca=!empty($slca)?preg_quote($slca[0],'/'):''; // comienzo de comentario alternativo de una línea
    $slc=preg_quote($slc,'/'); // preparar para su uso en la expresión regular
    $mlcs=preg_quote($mlcs,'/'); // preparar para su uso en la expresión regular
    $mlce=preg_quote($mlce,'/'); // preparar para su uso en la expresión regular
    if(!empty($slca))$slca=preg_quote($slca,'/'); // preparar para su uso en la expresión regular
    $i=$pos=0;
    $len=mb_strlen($text);
    $result=''; // texto final
    $comment=false;
    while($pos<$len){
      $partial=mb_substr($text,$pos,min($len-$pos,1024));
      $res=null;
      switch(true){
        case preg_match('/^[\r\n\f]+/us',$partial,$res): // bloques de múltiples saltos de línea fuera de cadenas
          $result.="\n"; // dejar sólo este salto de línea
        break;
        case preg_match('/^'.$slc.'.*?(?:\r\n?|\n\r?|$)/s',$partial,$res): // comentario de una sola línea, eliminar
        case $slca&&preg_match('/^'.$slca.'.*?(?:\r\n?|\n\r?|$)/s',$partial,$res): // comentario de una sola línea alternativo, eliminar
          $result.="\n";
        break;
        case preg_match('/^'.$mlcs.'(?!@)/us',$partial,$res): // comentario multilínea, pero no compilación condicional, así que ignorar las cadenas de su interior
          if(preg_match('/(?<!@)'.$mlce.'|$/us',$partial,$res,PREG_OFFSET_CAPTURE)){ // final de comentario o de archivo (no se admiten anidamientos)
            $newpos=$res[0][1]; // aquí empezó el final de comentario
            $pos+=$newpos+mb_strlen($res[0][0]); // saltar hasta aquí y el final de comentario también (eliminarlo)
            $res[0]=''; // y vaciar el resultado, para que no se altere la posición actual
          }
        break;
        case preg_match('/^'.$mlcs.'@(.*)?@'.$mlce.'/s',$partial,$res): // compilación condicional, así que respetarla
        case preg_match('/^\/(?:(?:\\\\.|[^\s\\\\])+?)\//us',$partial,$res): // expresiones regulares de JS, respetarlas
        case preg_match('/^(?:\\\\)+[\'\"]/us',$partial,$res): // comilla escapada, respetarla
        case preg_match('/^((?:\'(?:\\\\.|[^\'])*\')|(?:\"(?:\\\\.|[^\"])*\"))/us',$partial,$res): // cadenas fuera de comentarios
        case preg_match('/^[^\'\"\\\\'.$pslc.$pmlc.$pslca.']+/us',$partial,$res): // saltar también cualquier cosa que esté entre cadenas y comentarios
          $result.=$res[0];
        break;
      }
      if($this->TablaLlena($res)){
        $pos+=mb_strlen($res[0]);
      }else{
        $result.=$partial[0]; // si no ha habido coincidencia, tomar el caracter tal y como viene
        $pos++;
      }
    }
    return $result;
  }
  private function LimpiaArchivo($fname){
    $result='';
    if(@file_exists($fname)&&@is_readable($fname)){
      $contenido=file_get_contents($fname);
      $result=$this->LimpiaCodigo($contenido);
    }
    return $result;
  }
  private function DarError($msg,$params=null){
    $msg=vsprintf($msg,$params);
    echo"Error: $msg\n<br/>";
    exit;
  }

  private function CapturaAjustes(&$texto){
    if(preg_match_all('/%([a-zA-Z_]\w*)\s*=\s*([^;]+?)\s*;\s*/su',$texto,$res,PREG_SET_ORDER)){ // capturar todos los ajustes
      for($i=0,$maxi=count($res);$i<$maxi;$i++){
        $this->ajustes[$res[$i][1]]=$res[$i][2]; // guardar ajustes
      }
    }
  }
  private function ProcesaToken($token,&$tokens){
    $result='';
    $pos=0;
    $len=mb_strlen($token);
    while($pos<$len){
      $partial=mb_substr($token,$pos);
      $res=array();
      switch(true){
        case preg_match('/^\s*\{([A-Z][A-Z\d_]*)\}/si',$partial,$res): // tokens
          $result.=isset($tokens[$res[1]])?$tokens[$res[1]]:$res[0]; // expandir el token
        break;
        case preg_match('/^[^\{]+/si',$partial,$res): // texto entre tokens
          $result.=$res[0];
        break;
      }
      if($this->TablaLlena($res))$pos+=mb_strlen($res[0]);
      else{
        $pos++;
        $result.=$partial[0];
      }
    }
    return $result;
  }
  private function CapturaTokens(&$texto){
    if(preg_match_all('/\s*([A-Z][A-Z\d_]*)\s*=\s*([^\r\n]+?)(?:\r\n?|\n\r?|$)/su',$texto,$res,PREG_SET_ORDER)){ // capturar todos los tokens
      for($i=0,$maxi=count($res);$i<$maxi;$i++){
        $this->tokens[$res[$i][1]]=$this->ProcesaToken(trim($res[$i][2]),$this->tokens); // guardar tokens expandidos
      }
    }
  }
  private function CapturaUnToken($indentRegex,&$texto){
    $result=''; // nada
    if(preg_match('/^'.$indentRegex.'('.self::TODO_TOKEN.')/s',$texto,$res)){
      $result=$res[1]; // capturar token
      $texto=mb_substr($texto,mb_strlen($res[0])); // recortar texto
// echo"Encontrado token $result y nos queda por delante :<pre><br/>".htmlspecialchars($texto)."</pre><br/>";
    }
    return $result;
  }
  private function CapturaEstadosSolos(&$texto){
    $result=array('','');
    if(preg_match('/^\s*->\s*/',$texto,$res)){ // indicador de transición, así que toca parar
      $texto=mb_substr($texto,mb_strlen($res[0])); // saltarse este trozo de código
      if(preg_match('/^((?:0|\d+|\&\w+)(?:\s*,\s*(?:0|\d+|\&\w+))*)\s*([;\{]|$)/',$texto,$res)){ // encontramos un estado o lista de estados, y final de línea o inicio de código
        $masCodigo=$res[2]=='{'; // necesitamos más código
        $estados=preg_split('/\s*,\s*/',$res[1],-1,PREG_SPLIT_NO_EMPTY); // los estados a los que ha transicionado
        $result=array($estados,$masCodigo);
      }else $this->DarError("Caracter inesperado '%s', pero se esperaba lista de estados",array($texto[0]));
    }
    return $result;
  }
  private function CapturaTransicion($indentRegex,&$texto,&$codigo){
    $result=false; // no hay que seguir capturando código, por defecto
    $transicion=array(); // transición
    $token=$this->CapturaUnToken($indentRegex,$texto); // capturar un token ahora
    if($token){
      if(preg_match('/^\s*->\s*/',$texto,$res)){ // indicador de transición, así que toca parar
        list($estados,$result)=$this->CapturaEstadosSolos($texto);
        $transicion=array('token'=>$token,'serial'=>false,'estados'=>$estados,'code'=>''); // inicializar el objeto
        $this->transiciones[]=$transicion;
      }else{ // no es iniciador de transición, así que puede ser otro token separado por espacios o comas
        if(preg_match('/^\s*,\s*('.self::TODO_TOKEN.')/',$texto,$res)){ // lista separada por comas
          //- Tenemos un token aislado y debemos seguir acumulando tokens hasta final de transición. Luego crearemos el código adecuado.
// echo"Lista separada por comas<br/>";
          $tokens=array($token); // meterlo en una tabla
          while(!preg_match('/^\s*->/',$texto)){
// echo"Texto restante: ".mb_strlen($texto)." caracteres - Res[0]=$res[0]<br/>";
            $tokens[]=$res[1];
            $texto=mb_substr($texto,mb_strlen($res[0])); // saltarse este bloque
// echo"Texto restante: ".mb_strlen($texto)." caracteres - Tokens después de consumir este:<pre>".htmlspecialchars(print_r($tokens,true))."</pre><br/>";
            preg_match('/^\s*,\s*('.self::TODO_TOKEN.')/',$texto,$res); // detectar el siguiente
          }
          list($estados,$result)=$this->CapturaEstadosSolos($texto);
          $transicion=array('token'=>$tokens,'serial'=>false,'estados'=>$estados,'code'=>''); // inicializar el objeto
          $this->transiciones[]=$transicion;
        }elseif(preg_match('/^\s+('.self::TODO_TOKEN.')/',$texto,$res)){ // lista separada por espacios
// echo"Lista separada por espacios<br/>";
          //- Tenemos un token aislado y debemos seguir acumulando tokens hasta final de transición. Luego crearemos el código adecuado.
          $tokens=array($token); // meterlo en una tabla
          while(!preg_match('/^\s*->/',$texto)){
// echo"Texto restante: ".mb_strlen($texto)." caracteres - Res[0]=$res[0]<br/>";
            $tokens[]=$res[1];
            $texto=mb_substr($texto,mb_strlen($res[0])); // saltarse este bloque
// echo"Texto restante: ".mb_strlen($texto)." caracteres - Tokens después de consumir este:<pre>".htmlspecialchars(print_r($tokens,true))."</pre><br/>";
            preg_match('/^\s+('.self::TODO_TOKEN.')/',$texto,$res); // detectar el siguiente
          }
          list($estados,$result)=$this->CapturaEstadosSolos($texto);
          $transicion=array('token'=>$tokens,'serial'=>true,'estados'=>$estados,'code'=>''); // inicializar el objeto
          $this->transiciones[]=$transicion;
        }
      }
    }else $this->DarError("Caracter inesperado '%s', pero se esperaba token",array($texto[0]));
// echo"Transiciones hasta el momento:<pre>".htmlspecialchars(print_r($this->transiciones,true))."</pre><br/>";
    return $result;
  }
  private function CapturaTransiciones(&$texto,$indent){
    $indentRegex='\s{'.$indent.',}'; // expresión regular de indentado para transiciones
    $lineas=preg_split('/(?:\r\n?|\n\r?)/s',$texto,-1,PREG_SPLIT_NO_EMPTY);
    $result=$texto; // salvar copia
    for($i=0,$maxi=count($lineas);$i<$maxi;$i++){
      $linea=&$lineas[$i]; // aislar la línea
      if(preg_match('/^'.$indentRegex.'/',$linea,$res)){ // detectada transición legal
// echo"Línea actual:<pre>'".htmlspecialchars($linea)."'</pre><br/>";
        $code='';
        $getMoreCode=$this->CapturaTransicion($indentRegex,$linea,$code); // capturar transición individual, y saber si hay que seguir capturando código o no
        if($getMoreCode){ // necesitamos más código
// echo"Necesitamos más código para la última transición capturada<br/>";
          $linea=&$lineas[++$i]; // siguiente línea
          while($i<$maxi&&!preg_match('/^'.$indentRegex.'\}/',$linea,$res)){ // mientras no estemos al final del código
// echo"Línea de código interior:<pre>'".htmlspecialchars($linea)."'</pre><br/>";
            $code.="$linea\n"; // agregar esta línea
            $linea=&$lineas[++$i]; // siguiente línea
          }
          if($i<$maxi)$i++; // saltar última línea (cierre de llaves)
          $code.="}\n"; // agregar el cierre de llaves
          $this->transiciones[count($this->transiciones)-1]['code']=$code; // y meterlo en su sitio (siempre en la última transición)
        }
      }else{ // hemos terminado
        $result=join("\n",array_slice($lineas,$i)); // texto restante
        break; // salir
      }
    }
// echo"Transiciones modificadas:<pre>".htmlspecialchars(print_r($this->transiciones,true))."</pre><br/>";
    return $result;
  }
  private function CapturaEstados($texto){
    $indent=0;
// echo"Buscando estados en:<pre><br/>".htmlspecialchars($texto)."</pre><br/>";
    while($texto&&preg_match('/^(0|\d+|\w+)\s*(?:\(\s*(END)\s*\))?:\s*(?:\r\n?|\n\r?|$)+/s',$texto,$res)){ // capturar comienzo de estado, mientras quede código que procesar
// echo"Estado encontrado:<br/><pre>".htmlspecialchars(print_r($res,true))."</pre><br/>";
      $nombreEstado=$res[1]; // nombre del estado
      $estadoFinal=!empty($res[2]); // ¿es estado final?
      $texto=mb_substr($texto,mb_strlen($res[0])); // resto del código
      $transiciones=array(); // las transiciones que contiene este estado
      if(preg_match('/^\s+/s',$texto,$res)){ // si hay espacios al comienzo (obligatorio)
        $indent=mb_strlen($res[0]); // indentación mínima a partir de ahora
        $texto=$this->CapturaTransiciones($texto,$indent); // capturar transiciones, manteniendo la indentación
        $transiciones=$this->transiciones; // copiar las encontradas
        $this->transiciones=array(); // y limpiar para otra ocasión
// echo"Texto restante tras capturar transiciones:<pre>".htmlspecialchars($texto)."</pre><br/>";
      }else{ // no hay espacios, final de definición de estado
        $indent=0; // restaurar indentación
      }
      $this->estados[$nombreEstado]=array('final'=>$estadoFinal,'trans'=>$transiciones); // guardar estado anterior
    }
  }
  private function CapuraEventos($texto){
    while($texto&&preg_match('/^\s*(0|\d+|\w+)\.(ENTER|EXIT|REPEAT)\s*(?<r>\{\s*((?:[^\{\}]++|(?&r))*)\s*\})\s*/s',$texto,$res)){ // mientras haya estados que capturar
      $nombreEstado=$res[1];
      $tipoEvento=$res[2];
      $codigo=$res[3];
      $texto=mb_substr($texto,mb_strlen($res[0])); // resto del código
      if(isset($this->estados[$nombreEstado])){
        if(!preg_match('/^\s*\{\s*\}\s*$/',$codigo))$this->eventos[$nombreEstado][$tipoEvento]=$codigo; // si no es vacío, meter código aquí
      }else $this->DarError("El estado '$nombreEstado' no está definido en el autómata");
    }
  }
  
  private function MuestraEventos(){
    $result='';
    foreach($this->eventos as $estado=>$datos){
      foreach($datos as $tipoEvento=>$codigo){
        // $result.=<<<__EOF
// function f_$estado_$tipoEvento {
  // $codigo
// }
// __EOF;
        $result.=<<<__EOF
  private function f_$estado_$tipoEvento {
    $codigo
  }
__EOF;
      }
    }
    return $result;
  }
  private function MuestraOpciones(){
    $result='';
    foreach($this->ajustes as $nombre=>$valor){
      $result.=<<<__EOF
'$nombre'=>$valor,
__EOF;
    }
    return $result;
  }
  private function MuestraTokens(){
    $result='';
    foreach($this->tokens as $nombre=>$regex){
      // $regex=preg_quote($regex,"'");
      // $regex=preg_replace('/\'/', '\\\'', $regex);
      $result.=<<<__EOF
'$nombre'=>'$regex',
__EOF;
    }
    return $result;
  }
  private function TokenACodigo($val){
    $result=$val; // dejar como viene
    switch(true){
      case preg_match('/^\$([A-Za-z_]\w*)$/s',$val): // variable
        $result='$'.$this->nombreAutomata."->getVariable('".$val."')";
      break;
      case preg_match('/^[-+]?\d+\s*\.\.\s*[-+]?\d+$/s',$val): // rango
        $result='$'.$this->nombreAutomata."->getRange('".$val."')";
      break;
      case preg_match('/^$\/(?:\/|[^\r\n]*?)\//s',$val): // expresión regular
      case preg_match('/^(?:[€$]|eof|error)$/s',$val): // transiciones especiales
        $result="'$val'";
      break;
      case preg_match('/^[A-Z]\w*$/s',$val): // tokens
        $result="'/".$this->tokens[$val]."/'"; // convertirlo en expresión regular
      break;
      case preg_match('/^([\'\"])/s',$val,$res): // cadenas
        $dq=$res[1]=='"'; // dobles comillas
        $result=($dq?"'":'"').$val.($dq?"'":'"'); // entrecomillar de forma inversa
      break;
    }
    return $result;
  }
  private function TipoDeToken($val){
    $result='string'; // por defecto
    switch(true){
      case preg_match('/^\$([A-Za-z_]\w*)$/s',$val): // variable
        $result='variable';
      break;
      case preg_match('/^[-+]?\d+\s*\.\.\s*[-+]?\d+$/s',$val): // rango
        $result='range';
      break;
      case preg_match('/^\/(?:\/|[^\r\n]*?)\/$/s',$val): // expresión regular
        $result='regexp';
      break;
      case preg_match('/^(?:[€$]|eof|error)$/s',$val): // transiciones especiales
        $result='special';
      break;
      case preg_match('/^[A-Z]\w*$/s',$val): // tokens
        $result='token';
      break;
      case preg_match('/^((?:\'(?:\\\\.|[^\'])*\')|(?:\"(?:\\\\.|[^\"])*\"))$/s',$val): // cadenas
        $result='string';
      break;
      case preg_match('/^[-+]?(?:\d+(?:\.\d*)?|0?\.\d+)$/s',$val): // números
        $result='number';
      break;
      case preg_match('/^(?:false|true)$/s',$val):
        $result='literal';
      break;
    }
    return $result;
  }
  private function EstadoACodigo($val){
    $result="'$val'"; // por defecto
    if(preg_match('/^\&(\w+)$/',$val,$res))$result="'{$res[1]}'";
    return $result;
  }
  private function PreprocesaCodigo($codigo,$estados){
    $result='';
    $len=mb_strlen($codigo);
    $pos=0;
    if(!is_array($estados))$estados=array($estados);
    while($pos<$len){
      $res=array();
      $parcial=mb_substr($codigo,$pos);
      switch(true){
        case preg_match('/^\$(\d+)/s',$parcial,$res): // sustitución de tokens
          $index=$res[1]*1;
          $result.=isset($estados[$index])?$estados[$index]:'';
        break;
        case preg_match('/^[^\$]/s',$parcial,$res): // resto del código
          $result.=$res[0];
        break;
      }
      if($this->TablaLlena($res))$pos+=mb_strlen($res[0]);
      else{
        $result.=$parcial[0];
        $pos++;
      }
    }
    return $result;
  }
  private function MuestraTransiciones($transiciones){
    $result='';
    for($i=0,$maxi=count($transiciones);$i<$maxi;$i++){
      $trans=&$transiciones[$i]; // atajo
      $tokens=&$trans['token']; // atajo
      $multi=count($tokens)>1; // ¿es transición de token múltiple?
      $serial=$trans['serial']; // ¿es transición múltiple serial?
      $estados=&$trans['estados']; // atajo
      $code=$this->PreprocesaCodigo($trans['code'],$tokens); // código preprocesado, con datos para algunos macros
      $states=join(",",array_map(array($this,'EstadoACodigo'),$estados)); // lista de estados
      $state=$this->EstadoACodigo($estados[0]);
      if($multi){ // plantilla de transiciones múltiples
        $result.="\ncase \${$this->nombreAutomata}->multiTokenMatch(array(";
        for($j=0,$maxj=count($tokens);$j<$maxj;$j++){
          $tipo=$this->TipoDeToken($tokens[$j]);
          $result.="array(".$this->TokenACodigo($tokens[$j]).",'".($tipo=='token'?'regexp':$tipo)."'),"; // poner el "token" como "regexp"
        }
        $result.="),".($serial?'true':'false')."):\n";
        $result.="$code\n\${$this->nombreAutomata}->gotoStates(array($states));\nbreak;\n";
      }else{ // plantillas de transiciones sencillas
        $token=&$trans['token']; // atajo
        switch(true){
          case 'error'==$token: // transición de error
            $result.=<<<__EOF
case \${$this->nombreAutomata}->isErrorState():
  $code
  \${$this->nombreAutomata}->gotoState('$state');
break;
__EOF;
          break;
          case 'eof'==$token: // transición de fin de archivo
            $result.=<<<__EOF
case \${$this->nombreAutomata}->isEOF():
  $code
  \${$this->nombreAutomata}->gotoState('$state');
break;
__EOF;
          break;
          case preg_match('/^[€$]$/',$token,$res): // transición épsilon
            $result.=<<<__EOF
case true:
  $code
  \${$this->nombreAutomata}->gotoState('$state');
break;
__EOF;
          break;
          case preg_match('/^([A-Z]\w*)$/',$token,$res): // transición por token
            $result.=<<<__EOF
case \${$this->nombreAutomata}->tokenMatch('$res[1]'):
  $code
  \${$this->nombreAutomata}->gotoStates(array($states));
break;
__EOF;
          break;
          case preg_match('/^(\/(?:\/|[^\r\n])+?\/)$/',$token,$res): // transición por regexp
            $result.=<<<__EOF
case \${$this->nombreAutomata}->regexpMatch('$res[1]'):
  $code
  \${$this->nombreAutomata}->gotoStates(array($states));
break;
__EOF;
          break;
          case preg_match('/^(\'(?:\\.|[^\\\'])*?\')$/',$token,$res): // transición por cadena comilla simple
            $result.=<<<__EOF
case \${$this->nombreAutomata}->stringMatch("$res[1]","'"):
  $code
  \${$this->nombreAutomata}->gotoStates(array($states));
break;
__EOF;
          break;
          case preg_match('/^(\"(?:\\.|[^\\\"])*?\")$/',$token,$res): // transición por cadena comilla doble
            $result.=<<<__EOF
case \${$this->nombreAutomata}->stringMatch('$res[1]','"'):
  $code
  \${$this->nombreAutomata}->gotoStates(array($states));
break;
__EOF;
          break;
          case preg_match('/^([-+]?\d+)\s*\.\.\s*([-+]?\d+)$/',$token,$res): // transición por rango
            $result.=<<<__EOF
case \${$this->nombreAutomata}->rangeMatch($res[1],$res[2]):
  $code
  \${$this->nombreAutomata}->gotoStates(array($states));
break;
__EOF;
          break;
          case preg_match('/^\$([A-Za-z_]\w*)$/',$token,$res): // transición por variable
            $result.=<<<__EOF
case \${$this->nombreAutomata}->variableMatch('$res[1]'):
  $code
  \${$this->nombreAutomata}->gotoStates(array($states));
break;
__EOF;
          break;
        }
      }
    }
    return $result;
  }
  private function MuestraEstados(){
    $result='';
    foreach($this->estados as $nombre=>$datos){
      $final=$datos['final'];
      $transiciones=$datos['trans'];
      if(count($transiciones)){
        ob_start();
?>
case '<?=$nombre?>':
  if(<?=$final?'true':'false'?>)$<?=$this->nombreAutomata?>->atEnd();
  if($<?=$this->nombreAutomata?>->isInState($state))$<?=$this->nombreAutomata?>->doRepeat($state); // eventos REPEAT
  else $<?=$this->nombreAutomata?>->doEnter($state); // eventos ENTER
  switch(true){
    <?=$this->MuestraTransiciones($transiciones)?>
    default:
      $<?=$this->nombreAutomata?>->error();
    break;
  }
  if(!$<?=$this->nombreAutomata?>->isErrorState())$<?=$this->nombreAutomata?>->doExit($state); // eventos EXIT si no hubo error
break;  
<?
        $result.=ob_get_clean();
      }else{
        $result.="case '$nombre':\n";
      }
    }
    return $result;
  }
/*
  private function GeneraAutomataFinal(){
    $result="<?\n";
    ob_start();
?>
require_once"automata.php"; // la clase que define y controla el autómata
<?=$this->codigoGlobal?>
<?=$this->MuestraEventos()?>
function exec<?=$this->nombreAutomata?>($file){
  $<?=$this->nombreAutomata?>=new Automata($file);
  $<?=$this->nombreAutomata?>->setOptions( // cambiar las opciones por defecto
    array(
      <?=$this->MuestraOpciones()?>
    )
  );
  $<?=$this->nombreAutomata?>->addTokens( // agregar los tokens
    array(
      <?=$this->MuestraTokens()?>
    )
  );
  while(!$<?=$this->nombreAutomata?>->isEOF()&&!$<?=$this->nombreAutomata?>->isErrorState()){
    $states=$<?=$this->nombreAutomata?>->getCurrentStates();
    // $newStates=array();
    foreach($states as $state){
      switch($state){
        <?=$this->MuestraEstados()?>
      }
    }
  }
  $result=$<?=$this->nombreAutomata?>->isEOF()?$<?=$this->nombreAutomata?>->isEndStateReached():false; // "true" si la evaluación fue correcta y "false" en caso contrario
  return array($result,$<?=$this->nombreAutomata?>); // devuelve un par de valores: el resultado de la evaluación, y el objeto autómata para que se puedan inspeccionar otros resultados :)
}
<?
    $result.=ob_get_clean();
    $result.="?>";
    return $result;
  }
*/
  private function GeneraAutomataFinal(){
    $result="<?\n";
    ob_start();
?>
require_once"automata.php"; // la clase que define y controla el autómata
<?=$this->codigoGlobal?>
class <?=$this->nombreAutomata?>Class extends Automata {
  protected
    $tokens=array(
      <?=$this->MuestraTokens()?>
    )
  ;
  
  private function makeTransitions(){
    foreach($this->states as $state){
      switch($state){
        <?=$this->MuestraEstados()?>
      }
    }
  }
  <?=$this->MuestraEventos()?>
  public function __construct($file) {
    parent::__construct($file);
    $this->setOptions(
      array(
        <?=$this->MuestraOpciones()?>
      )
    );
  }
  public function execute($text = ''){
    $this->reset();
    if($text||($this->inputFile&&is_file($this->inputFile)&&is_readable($this->inputFile))){
      $this->text=!$text?@file_get_contents($this->inputFile):$text;
      $this->len=strlen($this->text);
      $this->states=array(0); // estado inicial
      while(!$this->isEOF()&&!$this->isErrorState()){
        $this->makeTransitions();
      }
    }
    return $this->isEndStateReached(); 
  }
}
// - Ejemplos a utilizar sólo durante el tiempo de desarrollo
$automata = new <?=$this->nombreAutomata?>Class('aventura.ave');
echo"Autómata:<pre>".print_r($automata,true)."</pre>";
<?
    $result.=ob_get_clean();
    $result.="?>";
    return $result;
  }
  private function CreaAutomata(&$texto){
    $result='';
    if(preg_match('/^\s*\[\s*AUTOMATA\s*:\s*([A-Za-z_]\w+)\s*\](.*)\s*\[\/\s*AUTOMATA\s*\]\s*$/su',$texto,$res)){ // el autómata completo
      $this->nombreAutomata=$res[1]; // el nombre del autómata
      $codigo=trim($res[2]); // el resto del código
      //%([a-zA-Z_]\w*)\s*=\s*([^;]+?)\s*; <-- un ajuste
      if(preg_match('/^\s*((?:%(?:[a-zA-Z_]\w*)\s*=\s*(?:[^;]+?)\s*;\s*)+)\s*\[\s*/su',$codigo,$res)){ // ¿hay ajustes? (siempre al inicio del archivo)
        $this->CapturaAjustes($res[1]); // capturarlos
        $codigo=mb_substr($codigo,strlen($res[1])); // y dejar el bloque atrás
      }
      // Ahora toca procesar TOKENS, GLOBALES, TRANSICIONES y EVENTOS en cuaquier orden que se puedan encontrar
      if(preg_match_all('/\s*\[\s*(TOKENS|GLOBALS|TRANSITIONS|EVENTS)\s*\](.*?)\s*\[\/\s*\1\s*\]\s*/su',$codigo,$res,PREG_SET_ORDER)){ // todos los demás bloques, sin orden particular alguno
        for($i=0,$maxi=count($res);$i<$maxi;$i++){
          $blockName=$res[$i][1];
          $blockCode=preg_replace(array('/^\s+/s','/\s+$/s'),'',$res[$i][2]); // quitar espacios al principio y al final
          switch($blockName){
            case'TOKENS': // generar tokens
              $this->CapturaTokens($blockCode);
            break;
            case'GLOBALS':
              $this->codigoGlobal="$blockCode\n"; // código global de un golpe
            break;
            case'TRANSITIONS':
              $this->CapturaEstados(preg_replace('/^\s+/','',$blockCode)); // capturar lo primordial de la "esencia" del autómata...
            break;
            case'EVENTS':
              $this->CapuraEventos($blockCode); // finalmente, los eventos (el resto de la "esencia" del autómata...)
            break;
          }
        }
      }
      $result=$this->GeneraAutomataFinal(); // generar el autómata final
    }
    return $result;
  }

  public function __construct(){
  }
  public function GeneraAutomata($name){
    $result=false;
    $fname="$name.def";
    $oname="$name.aut.php";
    $archivo=$this->LimpiaArchivo($fname);
    if($archivo){ // si hay contenido
      $salida=$this->CreaAutomata($archivo); // crear el autómata
      if($salida){ // si se ha creado
        file_put_contents($oname,$salida); // y crear el PHP adecuado
echo"Archivo resultante:<pre><br/>".htmlspecialchars($salida)."</pre><br/>";
        $result=true;
      }
    }
    return $result;
  }
}
$creador=new CreadorAutomatas();
$creador->GeneraAutomata('autodef-php'); // generar este autómata
?>