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
 * Strings for component 'gradeexport_group', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package   gradeexport_group
 * @copyright 2024 Juan Pablo de Castro <juan.pablo.de.castro@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['addedtogroup'] = 'Se han añadido {$a->added} estudiantes al grupo "<a href="{$a->url}">{$a->groupname}</a>"';
$string['eventgradeexported'] = 'Grupo creado a partir de calificaciones';
$string['pluginname'] = 'Notas a grupo';
$string['privacy:metadata'] = 'El plugin de exportación de notas a grupo no almacena datos personales..';
$string['group:publish'] = 'Exportar notas a grupo';
$string['group:view'] = 'Usar exportación de notas a grupo';
$string['gradeitem'] = 'Elemento de calificación';
$string['failed'] = 'Suspenso';
$string['failednograde'] = 'Suspenso o sin nota';
$string['nograde'] = 'Sin nota';
$string['approved'] = 'Aprobado';
$string['gradesgroupdescription'] = 'Grupo con estudiantes que tienen "{$a->itemname}" con calificación: {$a->status}';
$string['status'] = 'Criterio de las calificaciones para exportar al grupo';
$string['source'] = 'Grupo de origen';
$string['source_help'] = 'Selecciona el grupo de estudiantes a copiar al grupo de destino filtrando por el criterio.';
$string['conditions'] = 'Criterios';
$string['conditions_help'] = 'Selecciona el criterio que tienen que cumplir las calificaciones para exportar al grupo de destino.';
$string['targetgroup'] = 'Grupo de destino';
$string['targetgroup_help'] = 'Define el grupo en el que se copiarán los estudiantes del grupo de origen.';
$string['groupname'] = 'Nombre del grupo de destino';
$string['groupdescription'] = 'Descripción del grupo de destino';
$string['cleangroup'] = 'Vaciar grupo de destino antes de exportar';
$string['cleangroup_help'] = 'Si está marcado, el grupo de destino se vaciará antes de exportar los estudiantes del grupo de origen.';