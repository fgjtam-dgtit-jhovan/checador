# Análisis de Incidencias y Reportes - Proyecto Checador

## 📋 RESUMEN EJECUTIVO

El proyecto utiliza un sistema jerárquico de permisos con **reglas especiales de negocio** para direcciones generales. 
Las **incidencias y reportes** se filtran por `general_direction_id` con lógica duplicada en múltiples ubicaciones.

---

## 1. INCIDENCIAS (Incidents)

### 1.1 Modelo: `app/Models/Incident.php`

```php
class Incident extends Model
{
    protected $fillable = [
        'employee_id',      // ← FK a Employee (NO employee_number)
        'record_id',
        'incident_type_id',
        'incident_state_id',
        'date'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);  // ← Se accede por employee->general_direction_id
    }
}
```

**Características:**
- ✅ Usa `employee_id` (relación directa con Employee)
- ✅ A través del employee, se obtiene `general_direction_id`
- ❌ NO hay scopes de Eloquent para filtrar por general_direction

---

### 1.2 Controller: `app/Http/Controllers/IncidentController.php`

#### **Método Principal: `index()`** (línea ~44)
```php
function index(Request $request)
{
    // Obtiene general_direction_id del parámetro o del usuario logueado
    $generalDirecctionId = $request->filled('gdi') 
        ? $request->input("gdi") 
        : intval(Auth::user()->general_direction_id);
    
    // Prepara rango de fechas según tipo de reporte (mensual/quincenal)
    $employees = $this->getEmployeesWithIncidentsByDirection(
        $generalDirecctionId,   // ← FILTRO POR GD
        $startOfMonth,
        $endOfMonth
    );
}
```

#### **Método Clave: `getEmployeesWithIncidentsByDirection()`** (línea 629)

```php
private function getEmployeesWithIncidentsByDirection(int $generalDirectionId, $from, $to)
{
    // Empleados especiales (reglas de negocio)
    $employeesVLCPC = [20902, 10829, 48461, 7057, 20882];
    $employeesProcesos = [15492, 35561, 30874, 24493, 26934, 22515, 28875];
    $allSpecialEmployees = array_merge($employeesVLCPC, $employeesProcesos);

    // ← FILTRO DE INCIDENCIAS POR GENERAL_DIRECTION_ID
    $incidentsQuery = Incident::whereBetween('date', [$from, $to]);

    // Aplicar reglas especiales según la GD seleccionada
    if ($generalDirectionId == 18) {
        // GD 18: Excluir empleados específicos
        $incidentsQuery->whereHas("employee", function ($employee) use ($generalDirectionId, $allSpecialEmployees) {
            $employee->where('general_direction_id', $generalDirectionId)
                ->whereNotIn('employee_number', $allSpecialEmployees);
        });
    } 
    elseif ($generalDirectionId == 16) {
        // GD 16: Incluir empleados de VLCPC (que están en GD 18)
        $incidentsQuery->whereHas("employee", function ($employee) use ($generalDirectionId, $employeesVLCPC) {
            $employee->where(function ($q) use ($generalDirectionId, $employeesVLCPC) {
                $q->where('general_direction_id', $generalDirectionId)
                    ->orWhereIn('employee_number', $employeesVLCPC);
            });
        });
    }
    else {
        // Resto de GDs: Comportamiento normal
        $incidentsQuery->whereHas("employee", function ($employee) use ($generalDirectionId) {
            $employee->where('general_direction_id', $generalDirectionId);
        });
    }

    $incidents = $incidentsQuery->get();
    // ... agrupa por employee_id
}
```

#### **Método: `getIncidentsByEmployee()`** (línea 323)

```php
function getIncidentsByEmployee(Request $request, string $employee_number)
{
    // Obtiene empleado (valida acceso internamente)
    $employee = $this->findEmployee($employee_number);
    
    // Muestra incidencias del empleado individual
    // La validación de acceso ocurre en findEmployee() → EmployeeService
}
```

#### **Método: `employeeIncidentsJson()`** (línea 383)

```php
function employeeIncidentsJson(Request $request, string $employee_number): JsonResponse
{
    // Retorna incidencias en JSON para un empleado específico
    // También valida acceso a través de findEmployee()
}
```

#### **Método Privado: `findEmployee()`** (línea 603)

```php
private function findEmployee(string $employee_number)
{
    try {
        return $this->employeeService->getEmployee($employee_number);
        // ← VALIDA ACCESO INTERNAMENTE (ver EmployeeService)
    } catch (ModelNotFoundException $nf) {
        return redirect()->back()->withErrors([
            "employee_number" => "Empleado no encontrado",
        ]);
    }
}
```

---

### 1.3 Rutas de Incidencias

**Archivo:** `routes/web.php` (línea 101)

```php
Route::prefix('incidents')->name("incidents.")->group(function() {
    Route::get('/', [IncidentController::class, 'index'])
        ->name('index');
    
    Route::get('/employee/{employee_number}', [IncidentController::class, 'getIncidentsByEmployee'])
        ->name('employee.index');
    
    Route::get('/employee/{employee_number}/raw-incidents', [IncidentController::class, 'employeeIncidentsJson'])
        ->name('employee.raw');
    
    Route::patch('/{incident_id}/state', [IncidentController::class, 'updateIncidentState'])
        ->name('state.update');
    
    Route::get('/report', [IncidentController::class, 'makeReport'])
        ->name('report.make');
});
```

**Protección:** Todas requieren `['auth', 'authorized.menu']` middleware

---

## 2. REPORTES (Reports)

### 2.1 Tipos de Reportes

**No hay modelos separados** - Los reportes son **generados dinámicamente**:

1. **Reportes Diarios:**
   - Generados en tiempo real
   - Almacenados en MongoDB (DailyRecord)
   - Factory: `app/Helpers/DailyReportFactory.php`
   - PDF Factory: `app/Helpers/DailyReportPdfFactory.php`

2. **Reportes Mensuales:**
   - Generados por Job (queue)
   - Almacenados en MongoDB (MonthlyRecord)
   - Factory: `app/Helpers/MonthlyReportFactory.php`
   - Excel: `app/Helpers/MonthlyReportExcel.php`

3. **Reportes de Incidencias:**
   - Helper: `app/Helpers/IncidentsReport.php`
   - Retorna Excel con datos de incidencias

---

### 2.2 Modelos MongoDB

#### **DailyRecord.php**
```php
class DailyRecord extends Model
{
    protected $connection = 'mongodb';

    protected $fillable = [
        'general_direction_id',      // ← FILTRO POR GD
        'direction_id',
        'subdirectorate_id',
        'department_id',
        'report_date',
        'all_employees',
        'data'
    ];
}
```

#### **MonthlyRecord.php**
```php
class MonthlyRecord extends Model
{
    protected $connection = 'mongodb';

    protected $fillable = [
        'general_direction_id',      // ← FILTRO POR GD
        'direction_id',
        'subdirectorate_id',
        'department_id',
        'year',
        'month',
        'all_employees',
        'data',
        'filePath'
    ];
}
```

---

### 2.3 Controller: `app/Http/Controllers/ReportController.php`

#### **Método: `index()`** (línea ~24)

```php
public function index()
{
    // Lista General Directions disponibles para generar reportes
    $generalDirections = GeneralDirection::select('id', 'name')
        ->orderBy('name', 'ASC')
        ->get();
    
    $generalDirectionId = Auth::user()->general_direction_id;
    
    // Retorna vista con opciones de filtro
}
```

#### **Método: `createDailyReport()`** (línea 60)

```php
public function createDailyReport(Request $request)
{
    // Valida parámetros: gd (general_direction_id) y d (date)
    $generalDirection = null;
    $dateReport = Carbon::parse($request->query('d'))->format("Y-m-d");
    $includeAllEmployees = $request->query('a', false);
    
    $AUTH_USER = Auth::user();
    
    // ← FILTRO POR GD
    if ($AUTH_USER->level_id == 1 && $request->has('gd')) {
        // Admin: puede seleccionar cualquier GD
        $generalDirection = GeneralDirection::where('id', $request->query('gd'))->first();
    } else {
        // No-admin: solo su propia GD
        $generalDirection = GeneralDirection::where('id', $AUTH_USER->general_direction_id)->first();
    }
    
    return Inertia::render("Reports/Daily", [
        "report" => Inertia::lazy(
            fn() => $this->makeDailyReport($AUTH_USER, $dateReport, $generalDirection, $includeAllEmployees)
        ),
    ]);
}
```

#### **Método Privado: `makeDailyReport()`** (línea 242)

```php
private function makeDailyReport($AUTH_USER, $dateReport, $generalDirection, $includeAllEmployees)
{
    // Intenta obtener reporte almacenado en MongoDB
    $mongoReportRecord = $this->getDailyReportStored(
        date: $dateReport,
        generalDirectionId: $generalDirection->id,  // ← FILTRO
        options: [
            'directionId' => ($AUTH_USER->level_id > 2) ? $AUTH_USER->direction_id : null,
            'subdirectorateId' => ($AUTH_USER->level_id > 3) ? $AUTH_USER->subdirectorate_id : null,
            'departmentId' => ($AUTH_USER->level_id > 4) ? $AUTH_USER->department_id : null,
        ],
        allEmployees: $includeAllEmployees
    );
    
    // Si no existe o es hoy, genera nuevo reporte
    $employees = $this->getEmployees($generalDirection->id, [
        'directionId' => ($AUTH_USER->level_id > 2) ? $AUTH_USER->direction_id : null,
        'subdirectorateId' => ($AUTH_USER->level_id > 3) ? $AUTH_USER->subdirectorate_id : null,
        'departmentId' => ($AUTH_USER->level_id > 4) ? $AUTH_USER->department_id : null,
    ]);
    
    // Usa DailyReportFactory para generar datos
    $dailyReportFactory = new DailyReportFactory($employees, $dateReport);
    $reportData = $dailyReportFactory->makeReportData();
    
    // Almacena en MongoDB (DailyRecord)
    $recordMongo = new DailyRecord();
    $recordMongo->general_direction_id = $generalDirection->id;  // ← ALMACENA GD
    $recordMongo->data = $reportData;
    $recordMongo->save();
}
```

#### **Método Privado: `getEmployees()`** (línea 390)

```php
private function getEmployees($generalDirectionId, $options)
{
    $employeesQuery = Employee::with(['workingHours'])
        ->select('id', 'general_direction_id', 'direction_id', 'subdirectorate_id', 'department_id', ...)
        ->where('status_id', 1)
        ->where('active', 1);

    // ← REGLAS ESPECIALES PARA GD 16, 17, 18
    $employeesVLCPC = [20902, 10829, 48461, 7057, 20882];
    $employeesProcesos = [15492, 35561, 30874, 24493, 26934, 22515, 28875];

    if ($generalDirectionId == 18) {
        $employeesQuery->where('general_direction_id', 18)
            ->whereNotIn('employee_number', $employeesVLCPC)
            ->whereNotIn('employee_number', $employeesProcesos);
    } 
    elseif ($generalDirectionId == 16) {
        $employeesQuery->where(function ($query) use ($employeesVLCPC) {
            $query->where('general_direction_id', 16)
                ->orWhereIn('employee_number', $employeesVLCPC);
        });
    } 
    elseif ($generalDirectionId == 17) {
        $employeesQuery->where(function ($query) use ($employeesProcesos) {
            $query->where('general_direction_id', 17)
                ->orWhereIn('employee_number', $employeesProcesos);
        });
    }
    else {
        $employeesQuery->where('general_direction_id', $generalDirectionId);
    }

    // Aplicar filtros jerárquicos adicionales
    if (isset($options['directionId'])) {
        $employeesQuery = $employeesQuery->where('direction_id', $options['directionId']);
    }
    if (isset($options['subdirectorateId'])) {
        $employeesQuery = $employeesQuery->where('subdirectorate_id', $options['subdirectorateId']);
    }
    if (isset($options['departmentId'])) {
        $employeesQuery = $employeesQuery->where('department_id', $options['departmentId']);
    }

    return $employeesQuery->get();
}
```

#### **Método: `createMonthlyReport()`** (línea 105)

```php
public function createMonthlyReport(Request $request)
{
    // Similar a createDailyReport pero con year/month
    $generalDirection = null;
    $year = $request->query('y', 0);
    $month = $request->query('m', 0);
    
    $AUTH_USER = Auth::user();

    if ($AUTH_USER->level_id == 1 && $request->has('gd')) {
        $generalDirection = GeneralDirection::where('id', $request->query('gd'))->first();
    } else {
        $generalDirection = GeneralDirection::where('id', $AUTH_USER->general_direction_id)->first();
    }

    // Dispara Job para generar reporte en background
    $reportData = $this->makeMonthlyReport($AUTH_USER, $dateReport, $generalDirection, $includeAllEmployees);
}
```

---

### 2.4 Rutas de Reportes

**Archivo:** `routes/web.php` (línea 113)

```php
Route::prefix("reports")->name('reports.')->group(function() {
    Route::get('', [ReportController::class, 'index'])
        ->name('index');
    
    Route::get('daily', [ReportController::class, 'createDailyReport'])
        ->name('daily.create');
    
    Route::get('daily/{report_name}/download', [ReportController::class, 'downloadDailyReporte'])
        ->name('daily.download');

    Route::get('monthly', [ReportController::class, 'createMonthlyReport'])
        ->name('monthly.create');
    
    Route::get('monthly/{report_name}/download', [ReportController::class, 'downloadMonthlyReporte'])
        ->name('monthly.download');
    
    Route::get('monthly/verify/{reportID}', [ReportController::class, 'verifyMonthlyReporte'])
        ->name('monthly.verify');
});
```

---

## 3. RELACIONES ENTRE INCIDENCIAS Y REPORTES

```
┌─────────────────────────────────────────┐
│         Employee                        │
│ ┌─────────────────────────────────────┐ │
│ │ - employee_id (PK)                  │ │
│ │ - general_direction_id ← FILTRO     │ │
│ │ - direction_id                      │ │
│ │ - subdirectorate_id                 │ │
│ │ - employee_number                   │ │
│ └─────────────────────────────────────┘ │
└──────────────┬──────────────────────────┘
               │
        (hasMany)
               │
    ┌──────────▼─────────┐
    │     Incident       │
    │ ┌──────────────────┤
    │ │ - employee_id    │ ← FK a Employee
    │ │ - date           │
    │ │ - type_id        │
    │ │ - state_id       │
    │ └──────────────────┤
    └────────────────────┘
               │
         (filtra en)
               │
    ┌──────────▼──────────────────┐
    │  IncidentController          │
    │  - index()                   │
    │  - getEmployeesWithIncidents │
    │    ByDirection()             │
    └──────────────────────────────┘


┌──────────────────────────────────────┐
│    ReportController                  │
│    - createDailyReport()             │
│    - createMonthlyReport()           │
│    - getEmployees()                  │ ← MISMO FILTRO
└──────────────────────────────────────┘
               │
        (almacena en)
               │
    ┌──────────▼──────────────┐        ┌──────────────────────┐
    │   DailyRecord (MongoDB) │        │ MonthlyRecord        │
    │                         │        │ (MongoDB)            │
    │ - general_direction_id  │        │                      │
    │ - report_date           │        │ - general_direction  │
    │ - data                  │        │ - year               │
    └─────────────────────────┘        │ - month              │
                                       │ - data               │
                                       └──────────────────────┘
```

---

## 4. VALIDACIÓN DE ACCESO

### 4.1 Flujo de Validación

```
┌─────────────────────────────────────────┐
│  Request a /incidents o /reports        │
└────────────────┬────────────────────────┘
                 │
                 ▼
    ┌────────────────────────────────┐
    │  Middleware: 'authorized.menu'  │
    │  Gate: 'validate-user-menu'     │
    └────────────────┬────────────────┘
                     │
                     ▼
    ┌────────────────────────────────┐
    │  Controller Método              │
    │  - Obtiene general_direction_id │
    └────────────────┬────────────────┘
                     │
                     ▼
    ┌────────────────────────────────┐
    │  EmployeeService::getEmployee() │
    │  (si accede a empleado específico)
    └────────────────┬────────────────┘
                     │
                     ▼
    ┌────────────────────────────────┐
    │  ValidateAccessEmployee         │
    │  ::validateUser(User, Employee) │
    └────────────────┬────────────────┘
                     │
         ┌───────────┴───────────┐
         ▼                       ▼
    ✅ PERMITIDO          ❌ UnauthorizedException
```

### 4.2 Métodos de Validación

**Archivo:** `app/Services/EmployeeService.php` (línea 275)

```php
public function getEmployee(string $employeeNumber)
{
    // 1. Obtiene empleado por plantilla_id
    $employee = Employee::where('plantilla_id', '1' . $employeeNumber)->first();
    
    // 2. Valida acceso (solo si no es Admin)
    if (Auth::user()->level_id > 1) {
        $__hasAccess = \App\Helpers\ValidateAccessEmployee::validateUser(
            Auth::user(),      // User
            $employee          // Employee
        );
        
        if (!$__hasAccess) {
            throw new UnauthorizedException("The user has no access to this employee.");
        }
    }
    
    return EmployeeViewModel::fromEmployeeModel($employee);
}
```

**Archivo:** `app/Helpers/ValidateAccessEmployee.php` (línea ~36)

```php
static function validateUser(User $user, Employee $employee)
{
    // Admin (level_id = 1): acceso a todo
    if ($user->level_id == 1) {
        return true;
    }

    $__hasAccess = true;

    if ($user->level_id > 1) {
        // Level >= 2: Debe coincidir general_direction_id
        if ($user->general_direction_id != $employee->general_direction_id) {
            $__hasAccess = false;
        }

        // Level >= 3: Debe coincidir direction_id
        if ($user->level_id >= 3 && $__hasAccess) {
            if ($user->direction_id != $employee->direction_id) {
                $__hasAccess = false;
            }
        }

        // Level >= 4: Debe coincidir subdirectorate_id
        if ($user->level_id >= 4 && $__hasAccess) {
            if ($user->subdirectorate_id != $employee->subdirectorate_id) {
                $__hasAccess = false;
            }
        }

        // Reglas especiales de negocio
        if ($__hasAccess && self::checkSpecialRules($user, $employee)) {
            return true;
        }
    }

    return $__hasAccess;
}

private static function checkSpecialRules(User $user, Employee $employee)
{
    $userGeneralDirectionId = $user->general_direction_id;
    $employeeNumber = $employee->employee_number;
    $employeeGeneralDirectionId = $employee->general_direction_id;

    // Caso 1: Usuario de GD 16 (VLCPC) puede ver empleados específicos de GD 17/18
    if ($userGeneralDirectionId == 16) {
        if ($employeeGeneralDirectionId == 17 || $employeeGeneralDirectionId == 18) {
            return in_array($employeeNumber, self::EMPLOYEES_VLCPC);
        }
    }

    // Caso 2: Usuario de GD 17 (Procesos) puede ver empleados específicos de GD 18
    if ($userGeneralDirectionId == 17) {
        if ($employeeGeneralDirectionId == 18) {
            return in_array($employeeNumber, self::EMPLOYEES_PROCESOS);
        }
    }

    return false;
}
```

---

## 5. SERVICIOS RELACIONADOS

### 5.1 EmployeeService

**Archivo:** `app/Services/EmployeeService.php`

**Métodos principales:**

| Método | Propósito | Filtro |
|--------|-----------|--------|
| `getEmployees($take, $skip, $filters)` | Lista empleados paginada | general_direction_id, direction_id, subdirectorate_id |
| `getEmployee($employeeNumber)` | Obtiene empleado + valida acceso | Valida con ValidateAccessEmployee |
| `getEmployeesOfUser()` | Obtiene empleados subordinados del usuario actual | Depende de level_id del usuario |

**Reglas de filtrado:**
- **Level 1 (Admin):** Acceso a todos los empleados
- **Level 2:** Filtra por su `general_direction_id`
- **Level 3:** Filtra por su `direction_id`
- **Level 4+:** Filtra por su `subdirectorate_id`

### 5.2 IncidentService

**Archivo:** `app/Services/IncidentService.php`

- Gestiona tipos de incidencias y justificaciones
- Define constantes de mapeos (FALTA, RETARDO, etc.)
- NO implementa lógica de filtrado por general_direction_id

---

## 6. HELPERS DE REPORTES

### 6.1 DailyReportFactory

```php
class DailyReportFactory {
    protected array $employees = [];
    protected DateTime $dateReport;

    function __construct($employees, $dateReport) { ... }
    
    function makeReportData() {
        // Genera datos de reporte diario por empleado
    }
}
```

### 6.2 MonthlyReportFactory

```php
class MonthlyReportFactory {
    protected array $employees = [];
    protected int $year;
    protected int $month;

    function __construct($employees, $year, $month) { ... }
    
    function makeReportData() {
        // Genera datos de reporte mensual por empleado
    }
}
```

### 6.3 IncidentsReport

```php
class IncidentsReport {
    private $employees;
    private $date;
    private $generalDirection;
    
    function create() {
        // Genera Excel con incidencias agrupadas
    }
}
```

---

## 7. DÓNDE SE APLICAN LOS FILTROS

### 7.1 Resumen de Puntos de Filtrado

| Ubicación | Componente | Filtro | Alcance |
|-----------|-----------|--------|---------|
| **IncidentController** | `index()` | `general_direction_id` | Incidencias por GD |
| **IncidentController** | `getEmployeesWithIncidentsByDirection()` | `general_direction_id` + reglas especiales | Empleados con incidencias |
| **ReportController** | `createDailyReport()` | `general_direction_id` | Reportes diarios |
| **ReportController** | `makeDailyReport()` | `general_direction_id` | Obtiene empleados para reporte |
| **ReportController** | `getEmployees()` | `general_direction_id` + reglas especiales | Empleados para reportes |
| **EmployeeService** | `getEmployees()` | `general_direction_id` + level_id | Lista de empleados |
| **EmployeeService** | `getEmployee()` | `ValidateAccessEmployee` | Acceso a empleado individual |

### 7.2 Lógica Duplicada

⚠️ **La lógica de filtrado se repite en:**

1. **IncidentController** - `getEmployeesWithIncidentsByDirection()`
2. **ReportController** - `getEmployees()`
3. **EmployeeService** - `getEmployees()` y `getEmployeesOfUser()`

**Reglas especiales para GD 16, 17, 18 están duplicadas en 3 ubicaciones**

---

## 8. INFORMACIÓN SOBRE ACCESO A REPORTES Y INCIDENCIAS

### 8.1 Quién puede acceder a qué

```
ADMIN (level_id = 1):
├─ Incidencias: ✅ Ver todas las GD/Direction/Subdirectorate
├─ Reportes Diarios: ✅ Generar para cualquier GD
└─ Reportes Mensuales: ✅ Generar para cualquier GD

GD MANAGER (level_id = 2):
├─ Incidencias: ✅ Solo su GD
├─ Reportes Diarios: ✅ Solo su GD
└─ Reportes Mensuales: ✅ Solo su GD

DIRECTOR (level_id = 3):
├─ Incidencias: ✅ Solo su Direction
├─ Reportes Diarios: ✅ Solo su Direction
└─ Reportes Mensuales: ✅ Solo su Direction

SUBDIRECTOR (level_id = 4):
├─ Incidencias: ✅ Solo su Subdirectorate
├─ Reportes Diarios: ✅ Solo su Subdirectorate
└─ Reportes Mensuales: ✅ Solo su Subdirectorate
```

### 8.2 Reglas Especiales

**GD 16 (VLCPC):**
- Puede ver empleados de su GD + 5 empleados específicos de GD 18

**GD 17 (Procesos):**
- Puede ver empleados de su GD + 7 empleados específicos de GD 18

**GD 18:**
- Excluye los empleados que fueron trasferidos a GD 16 y GD 17

---

## 9. DÓNDE APLICAR CAMBIOS DE PERMISO

### 9.1 Para Nuevo Requisito de Permisos

**Si necesitas cambiar o agregar nuevas reglas de permiso:**

1. **ValidateAccessEmployee.php** - Validación de acceso a empleados individuales
   - Método: `validateUser()` y `checkSpecialRules()`
   - ⚠️ Esta es la FUENTE DE VERDAD para validación individual

2. **EmployeeService.php** - Filtrado de listas de empleados
   - Método: `getEmployees()` y `getEmployeesOfUser()`
   - ⚠️ Debe mantener SINCRONIZACIÓN con ValidateAccessEmployee

3. **IncidentController.php** - Filtrado de incidencias
   - Método: `getEmployeesWithIncidentsByDirection()`
   - ⚠️ Debe duplicar la misma lógica que ReportController

4. **ReportController.php** - Filtrado de reportes
   - Método: `getEmployees()`
   - ⚠️ Debe duplicar la misma lógica que IncidentController

### 9.2 Checklist para Nuevo Cambio de Permiso

- [ ] Actualizar `ValidateAccessEmployee::validateUser()` (validación individual)
- [ ] Actualizar `ValidateAccessEmployee::checkSpecialRules()` (reglas especiales)
- [ ] Actualizar `EmployeeService::getEmployees()` (filtro en lista)
- [ ] Actualizar `EmployeeService::getEmployeesOfUser()` (empleados del usuario)
- [ ] Actualizar `IncidentController::getEmployeesWithIncidentsByDirection()` (filtro incidencias)
- [ ] Actualizar `ReportController::getEmployees()` (filtro reportes)
- [ ] Probar acceso en UI: Incidencias, Reportes Diarios, Reportes Mensuales
- [ ] Verificar que las constantes de empleados especiales sean consistentes en todas las ubicaciones

---

## 10. FACTORES CLAVE PARA IMPLEMENTAR PERMISOS

### ✅ Lo que SIEMPRE está presente:

1. **Usuario logueado tiene:**
   - `level_id` (1=Admin, 2=GD Manager, 3=Director, 4=Subdirector)
   - `general_direction_id` (su área de responsabilidad)
   - `direction_id` (para directors)
   - `subdirectorate_id` (para subdirectors)

2. **Empleado tiene:**
   - `general_direction_id` (a qué área pertenece)
   - `direction_id`
   - `subdirectorate_id`
   - `employee_number` (para reglas especiales)

3. **Jerarquía de filtrado:**
   - Level >= 2: filtrar por general_direction_id
   - Level >= 3: filtrar por direction_id
   - Level >= 4: filtrar por subdirectorate_id

### 🎯 Puntos de entrada para permisos:

1. **Acceso individual:** `ValidateAccessEmployee::validateUser()`
2. **Listados:** `EmployeeService::getEmployees()`
3. **Incidencias:** `IncidentController::getEmployeesWithIncidentsByDirection()`
4. **Reportes:** `ReportController::getEmployees()`

---

## CONCLUSIÓN

El sistema de permisos es **jerárquico con excepciones especiales**. Para aplicar cambios de permiso:

1. **Entender el nivel del usuario** (level_id)
2. **Identificar dónde se filtra** (4 ubicaciones principales)
3. **Mantener sincronización** entre ValidateAccessEmployee, EmployeeService, IncidentController y ReportController
4. **Considerar reglas especiales** para GD 16, 17, 18
5. **Probar en todos los puntos de acceso** (empleados, incidencias, reportes)
