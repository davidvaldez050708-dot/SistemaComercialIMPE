<?php
require_once dirname(__DIR__).'/app/controllers/ReminderController.php';
$r=new ReflectionClass(ReminderController::class);$controller=$r->newInstanceWithoutConstructor();$sort=$r->getMethod('ordenarRecordatorios');$sort->setAccessible(true);
$items=[
 ['id'=>'phone-old','telefonia_id'=>1,'prioridad'=>1,'fecha'=>'2026-10-18 09:00:00'],
 ['id'=>'follow-next','prioridad'=>1,'fecha'=>'2026-10-18 12:00:00'],
 ['id'=>'phone-new','telefonia_id'=>2,'prioridad'=>1,'fecha'=>'2026-10-18 13:00:00'],
 ['id'=>'overdue','estado'=>'vencida','fecha'=>'2026-10-17 10:00:00'],
 ['id'=>'follow-first','prioridad'=>1,'fecha'=>'2026-10-18 10:00:00'],
 ['id'=>'today','estado'=>'hoy','fecha'=>'2026-10-18 11:00:00'],
];
$expected=['overdue','follow-first','follow-next','phone-new','phone-old','today'];
for($i=0;$i<50;$i++){shuffle($items);$actual=array_column($sort->invoke($controller,$items),'id');if($actual!==$expected)throw new RuntimeException('Orden telefónico y prioridad operativa inconsistentes');}
echo "Campana: orden total estable, teléfono reciente primero y prioridad de Seguimiento conservada (50 permutaciones): OK\n";
