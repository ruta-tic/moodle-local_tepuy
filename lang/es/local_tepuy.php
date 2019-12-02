<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *
 * @package   local_tepuy
 * @copyright 2019 David Herney - cirano
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Potenciador de Tepuy';

$string['invalidjson'] = 'Cadena JSON inválida';
$string['actionrequired'] = 'Una acción es requerida';
$string['skeyrequired'] = 'Una clave de sesión es requerida';
$string['invalidaction'] = 'Acción inválida: {$a}';
$string['invalidkey'] = 'Clave inválida';
$string['generalexception'] = 'Excepción: {$a}';
$string['newchatconnectionerror'] = 'Error de conexión a nuevo chat';
$string['settingsnotfound'] = 'Configuración de juego no encontrada';
$string['userchatnotfound'] = 'Chat de usuario no encontrado';
$string['chatnotavailable'] = 'Chat no disponible';
$string['notgroupnotteam'] = 'No existe el grupo relacionado';
$string['cardcodeandtyperequired'] = 'Tipo y código de carta son requeridos';
$string['invalidcardcode'] = 'Código de carta inálido';
$string['invalidcardtype'] = 'Tipo de carta inválido';
$string['typenotallowed'] = 'El usuario actual no puede jugar este tipo de carta';
$string['carddontplayed'] = 'Carta no jugada';
$string['notmembersingroup'] = 'No hay miembros en el grupo {$a}';
$string['restardbconnection'] = 'La conexión con la base de datos ha sido reiniciada';
$string['fieldrequired'] = 'El campo {$a} es requerido.';
$string['errorgamestart'] = 'Un juego está activo en este momento';
$string['notrequiredfiles'] = 'El juego actual no cuenta con el archivo requerido';
$string['notrequiredtechnologies'] = 'El juego actual no cuenta con la tecnología requerida';
$string['notresources'] = 'El juego actual no tiene recursos para lo que se solicita';
$string['notrunningaction'] = 'La política no está en ejecución';
$string['notrunningtech'] = 'La tecnología no está en ejecución';
$string['crontask'] = 'Tepuy cron';
$string['components_socket_cronuri'] = 'URI del cron para el componente -socket-';
$string['components_socket_cronuri_desc'] = 'Algo como ws://localhost:1234?skey=XXXXXXXXXXXXXXXXXXXXXXXXX';
$string['techrunningrequired'] = 'Una tecnología es requerida para ejecutar esta política';
$string['usernotintogroup'] = 'El usuario no se encontró en el grupo actual';

// Original chat system messages
$string['messagebeepseveryone'] = '{$a} envía un beep a todos';
$string['messagebeepsyou'] = '{$a} le acaba de enviar un beep';
$string['messageenter'] = '{$a} entró a la sala';
$string['messageexit'] = '{$a} salió de la sala';
$string['messageyoubeep'] = 'Su señal de sonido beep {$a}';

// Local chat messages
$string['messageactionplaycard'] = '{$a} ha jugado una carta';
$string['messageactionunplaycard'] = '{$a} ha removido una carta';
$string['messageactionendcase'] = '{$a} ha finalizado el intento';
$string['messageactionplayerconnected'] = '{$a} se ha conectado';
$string['messageactionplayerdisconnected'] = '{$a} se ha desconectado';
$string['messageactioncasefailed'] = 'Caso fallido';
$string['messageactioncasepassed'] = 'Caso aprobado';
$string['messageactionattemptfailed'] = 'Intento fallido';
$string['messageactionattemptpassed'] = 'Intento aprobado';
$string['messageactionsc_gamestart'] = '{$a} ha iniciado el juego';
$string['messageactionsc_changetimeframe'] = '{$a} ha cambiado la velocidad del juego';
$string['messageactionsc_playaction'] = '{$a} ha jugado una política';
$string['messageactionsc_playtechnology'] = '{$a} ha jugado una tecnología';
$string['messageactionsc_stopaction'] = '{$a} ha detenido una política';
$string['messageactionsc_stoptechnology'] = '{$a} ha detenido una tecnología';
$string['messageactionsc_actioncompleted'] = 'Una política ha sido completada';
$string['messageactionsc_technologycompleted'] = 'Una tecnología ha sido completada';
$string['messageactionsc_gameover'] = 'El juego ha sido finalizado manualmente por {$a}';
$string['messageactionendtechnology'] = 'La tecnología -{$a}- ha finalizado';
$string['messageactionendaction'] = 'La política -{$a}- ha finalizado';
$string['messageactionautogameover'] = 'El juego ha finalizado';
