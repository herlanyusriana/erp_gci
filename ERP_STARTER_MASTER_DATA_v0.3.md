# ERP Manufacturing — Starter + Master Data v0.3

> Sumber awal: `master data 20260908.xlsx`  
> Scope dokumen ini hanya **Master Data yang memang terdapat di Excel**.  
> BOM, Incoming, Inventory, Production, Planning, QC, dan Outgoing belum dibahas pada tahap ini.

---

#
---

# 0. Project Starter / Foundation

Sebelum masuk ke Master Data, project perlu mempunyai foundation yang reusable.

Struktur awal:

```text
PROJECT STARTER
│
├── Authentication
├── RBAC
├── Config Master
├── Application Settings
├── Reusable Components
├── Layout
├── Theme
└── Audit Foundation
```

Foundation ini dibuat sekali dan digunakan oleh seluruh modul ERP.

---

## 0.1 Authentication

Authentication menjadi pintu masuk aplikasi.

Fitur minimum:

```text
Login
Logout
Forgot Password
Reset Password
Change Password
Profile
Session
```

User tidak langsung dikaitkan ke permission satu per satu.

Permission diberikan melalui Role.

---

## 0.2 RBAC

RBAC = Role Based Access Control.

Struktur dasar:

```text
users
roles
permissions
role_permissions
user_roles
```

Contoh Role:

```text
Super Admin
IT Admin
Management
PPIC
Purchasing
Warehouse
Production
QC
Engineering
```

Contoh Permission:

```text
part.view
part.create
part.update
part.delete

supplier.view
supplier.create
supplier.update

machine.view
machine.create
machine.update

process.view
process.create
process.update
```

Format permission direkomendasikan:

```text
module.action
```

Contoh:

```text
master.part.view
master.part.create
master.part.update
master.part.delete
```

atau jika ingin lebih ringkas:

```text
part.view
part.create
part.update
part.delete
```

Permission dicek di Backend.

Frontend hanya menyesuaikan tampilan.

Artinya:

```text
Hidden Button ≠ Security
```

User tetap harus divalidasi pada Laravel Policy / Middleware / Authorization Layer.

---

## 0.3 Config Master

Config Master digunakan untuk konfigurasi aplikasi yang dapat berubah tanpa harus mengubah source code.

Contoh:

```text
config_masters
-------------------------
id
group
key
value
data_type
description
is_active
created_at
updated_at
```

Contoh data:

```text
SYSTEM
company_name
PT Geum Cheon Indo

SYSTEM
timezone
Asia/Jakarta

SYSTEM
date_format
DD/MM/YYYY

SYSTEM
currency
IDR

PART
default_uom
PCS
```

Config dapat dikelompokkan menjadi:

```text
SYSTEM
COMPANY
PART
SUPPLIER
PRODUCTION
INVENTORY
UI
```

Tidak semua business rule harus menjadi Config Master.

Config Master digunakan hanya untuk nilai yang memang bersifat configurable.

---

## 0.4 Application Settings

Selain Config Master, aplikasi mempunyai setting umum.

Contoh:

```text
Company Name
Company Logo
Application Name
Application Version
Timezone
Date Format
Currency
Decimal Precision
Default Language
```

Untuk tahap awal:

```text
Application Name:
Geum Cheon ERP

Company:
PT Geum Cheon Indo
```

---

## 0.5 Reusable Components

ERP akan menggunakan komponen reusable agar seluruh modul konsisten.

Contoh komponen:

```text
AppButton
AppInput
AppSelect
AppTextarea
AppCheckbox
AppRadio
AppSwitch

AppTable
AppPagination
AppSearch
AppFilter

AppModal
AppDrawer
AppDialog

AppCard
AppBadge
AppAlert

AppBreadcrumb
AppTabs

AppDatePicker
AppDateTimePicker

AppFileUpload
AppLoading
AppEmptyState
AppConfirmDialog
```

Komponen business-specific dapat dibuat setelah domain mulai berkembang.

Contoh:

```text
PartSelect
SupplierSelect
MachineSelect
ProcessSelect
UomSelect
```

Tujuannya supaya fitur baru tidak membuat komponen UI sendiri-sendiri dengan gaya yang berbeda.

---

## 0.6 Geum Cheon Theme

Tema utama aplikasi:

```text
Blue
White
Neutral Gray
```

Karakter UI:

```text
Clean
Industrial
Professional
Simple
Dense enough for ERP
Readable
Consistent
```

Tidak menggunakan purple sebagai warna utama.

### Warna Utama

Contoh design token:

```text
Primary Blue
#0B3A6E

Primary Blue Hover
#092F59

Primary Blue Light
#EAF2FA

White
#FFFFFF

Background
#F7F9FC

Border
#D9E2EC

Text Primary
#17202A

Text Secondary
#64748B

Success
#15803D

Warning
#B45309

Danger
#B91C1C

Info
#0369A1
```

Nilai final masih dapat disesuaikan dengan identitas visual perusahaan.

---

## 0.7 UI Layout

Struktur layout utama:

```text
┌───────────────────────────────────────────────┐
│ Topbar                                        │
├───────────────┬───────────────────────────────┤
│               │ Breadcrumb                    │
│               ├───────────────────────────────┤
│ Sidebar       │                               │
│               │ Page Content                  │
│               │                               │
│               │                               │
└───────────────┴───────────────────────────────┘
```

### Sidebar

Contoh:

```text
Dashboard

Master Data
├── Part
├── Supplier
├── Material Substitute
├── Machine
├── Process
└── UOM

Administration
├── Users
├── Roles
├── Permissions
└── Config Master
```

Menu lain ditambahkan setelah domain lain dibahas.

---

## 0.8 Component Style

Komponen dibuat konsisten.

### Button

Variant awal:

```text
Primary
Secondary
Outline
Danger
Ghost
```

### Badge

Contoh:

```text
ACTIVE
INACTIVE
DRAFT
APPROVED
REJECTED
HOLD
```

### Table

Table ERP harus mendukung:

```text
Search
Filter
Sort
Server-side Pagination
Column Visibility
Loading State
Empty State
Row Action
Permission-aware Action
```

Data besar tidak di-load seluruhnya ke browser.

---

## 0.9 Form Standard

Setiap form mempunyai pola konsisten:

```text
Header
Description
Form Section
Validation
Action Bar
```

Contoh:

```text
Part Master

General Information
├── Part Number
├── Part Name
├── Part Type
├── Model
└── UOM

Specification
├── Size
└── Nett Weight

Status
└── Active

[Cancel] [Save]
```

Server tetap menjadi source of truth untuk validation.

---

## 0.10 Audit Foundation

Walaupun Audit Log lengkap belum dibahas, foundation sebaiknya sudah menyiapkan field:

```text
created_by
updated_by
created_at
updated_at
```

Untuk tabel penting.

Contoh:

```text
parts
suppliers
machines
processes
config_masters
```

Soft delete dipertimbangkan untuk Master Data yang perlu histori.

Contoh:

```text
deleted_at
```

Tetapi penggunaannya tidak otomatis untuk semua tabel.

---

## 0.11 Recommended Starter Structure

Dengan stack:

```text
Laravel 12
Vue 3
TypeScript
Inertia.js
Tailwind CSS
PostgreSQL
Redis
```

Struktur frontend dapat diarahkan seperti:

```text
resources/js
│
├── Components
│   ├── Base
│   ├── Form
│   ├── Table
│   ├── Feedback
│   └── Domain
│
├── Layouts
│   ├── AppLayout
│   └── AuthLayout
│
├── Pages
│   ├── Dashboard
│   ├── Master
│   └── Administration
│
├── Composables
├── Types
├── Utils
└── Config
```

Backend:

```text
app
│
├── Models
├── Policies
├── Services
├── Actions
├── Http
│   ├── Controllers
│   ├── Requests
│   └── Middleware
└── Support
```

Business logic tidak diletakkan di Vue Component.

---

## 0.12 Urutan Implementasi Starter

Urutan awal:

```text
1. Project Setup
2. Database Setup
3. Authentication
4. User Management
5. RBAC
6. Base Layout
7. Geum Cheon Theme
8. Reusable Components
9. Config Master
10. Master Data
```

Setelah foundation stabil:

```text
Master Part
Master Supplier
Material Substitute
Machine
Process
UOM
```

baru dikembangkan.


---

## 0.13 Navigation Pattern — Full Launcher

ERP menggunakan **Full Application Launcher**.

Tidak menggunakan permanent sidebar.

Struktur navigasi:

```text
Main Launcher
    ↓
Module Launcher
    ↓
Feature Page
```

Contoh:

```text
GEUM CHEON ERP

[ Master Data ]
[ Incoming ]
[ Inventory ]
[ Production ]
[ Quality ]
[ Outgoing ]
[ Purchasing ]
[ Planning ]
[ Administration ]
[ Settings ]
```

Saat membuka Master Data:

```text
MASTER DATA

[ Part ]
[ Supplier ]
[ Material Substitute ]
[ Machine ]
[ Process ]
[ UOM ]
```

### Back Button

Setiap halaman selain Main Launcher wajib memiliki tombol:

```text
← Back
```

Perilaku:

```text
Feature Page
→ Back ke Module Launcher

Module Launcher
→ Back ke Main Launcher
```

Contoh:

```text
← Back

Part Master
```

dan:

```text
← Back

MASTER DATA

[ Part ]
[ Supplier ]
[ Machine ]
```

Tombol Back harus:

- Selalu berada di posisi yang konsisten.
- Mudah terlihat.
- Tidak bergantung hanya pada browser back.
- Mengikuti hierarchy aplikasi, bukan sekadar history browser.
- Tetap menghormati RBAC user.

Navigation style final:

```text
Permanent Sidebar : NO
Primary Navigation: Full App Launcher
Module Navigation : Module Launcher
Back Navigation   : REQUIRED
```

# 1. Tujuan

Tahap pertama ERP adalah membentuk Master Data yang bersih dan konsisten dari data Excel existing.

Prinsip yang dipakai:

- Istilah utama sistem menggunakan **Part**, bukan Item.
- Part Number menjadi identitas bisnis utama sebuah Part.
- Data master tidak dipisahkan secara sembarangan jika sebenarnya mewakili konsep yang sama.
- Nama field ERP tetap mengikuti istilah yang familiar di manufacturing.
- Struktur database boleh dinormalisasi, tetapi informasi dari Excel tidak boleh hilang.
- Flow bisnis belum dibahas di dokumen ini.

---

# 2. Master Data yang Terdapat di Excel

Workbook saat ini memiliki data berikut yang masuk scope Master Data:

```text
MASTER DATA
│
├── Part
│   ├── Finished Good
│   ├── Material
│   └── WIP
│
├── Material Substitute
│
├── Supplier
│
├── Machine
│
└── Process
```

Sumber sheet:

| Master Data | Sheet Excel |
|---|---|
| Finished Good | `master FG` |
| Material | `master mtrl` |
| Material Substitute | `master substitute` |
| WIP | `master WIP` |
| Machine & Process | `master machine` |

Sheet `BOM` **belum masuk pembahasan tahap ini**.

---

# 3. Konsep Part

Finished Good, Material, dan WIP sama-sama diperlakukan sebagai **Part**.

Jadi pada database ERP tidak perlu membuat tiga tabel utama yang berdiri sendiri seperti:

```text
master_fg
master_material
master_wip
```

Direkomendasikan menggunakan satu master utama:

```text
parts
```

dengan pembeda:

```text
part_type
```

Part Type awal yang berasal dari Excel:

```text
FG        Finished Good
MATERIAL  Material
WIP       Work In Process
```

Struktur awal:

```text
parts
--------------------------------
id
part_number
part_name
part_type_id
model
uom_id
size
nett_weight
is_active
remarks
created_at
updated_at
```

Catatan:

- Tidak semua field berlaku untuk semua Part Type.
- `size` terutama berasal dari Material.
- `nett_weight` terutama berasal dari Finished Good.
- WIP memiliki struktur lebih sederhana.
- Field tambahan baru ditambahkan jika memang ditemukan kebutuhan bisnisnya.

---

# 4. Part Type

Master:

```text
part_types
-------------------------
id
code
name
```

Data awal:

| Code | Name |
|---|---|
| FG | Finished Good |
| MATERIAL | Material |
| WIP | Work In Process |

Relasi:

```text
part_types
    │
    └──< parts
```

---

# 5. Finished Good

Sumber:

```text
Sheet: master FG
```

Kolom Excel:

```text
FG NAME
FG MODEL
FG Part #
FG UoM
Nett Weight
```

Mapping ke ERP:

| Excel | ERP |
|---|---|
| FG NAME | `part_name` |
| FG MODEL | `model` |
| FG Part # | `part_number` |
| FG UoM | `uom_id` |
| Nett Weight | `nett_weight` |
| - | `part_type = FG` |

Contoh dari Excel:

```text
Part Number : AAN30056405
Part Name   : BASE ASSEMBLY,COMPRESSOR
Model       : VT 12
Type        : FG
Nett Weight : 0.978296
```

Contoh lain:

```text
AAN74552009
BASE ASSEMBLY,COMPRESSOR
Omega Diet
```

Catatan sementara:

- `FG Part #` harus menjadi unique business key.
- `FG NAME` tidak perlu unique.
- `FG MODEL` tidak perlu unique.
- Pada data Excel saat ini terdapat FG UoM yang belum terisi, sehingga ERP perlu menentukan apakah UoM wajib pada saat data cleansing/import.

---

# 6. Material

Sumber:

```text
Sheet: master mtrl
```

Kolom Excel:

```text
Material Name
Material Model
Material Part #
Material Size
UoM
```

Mapping:

| Excel | ERP |
|---|---|
| Material Name | `part_name` |
| Material Model | `model` |
| Material Part # | `part_number` |
| Material Size | `size` |
| UoM | `uom_id` |
| - | `part_type = MATERIAL` |

Contoh:

```text
Part Number : BPSH0256401480
Part Name   : BACK PLATE
Model       : OMEGA 6
Size        : 0.25 X 640 X 1480
UoM         : SHEET
Type        : MATERIAL
```

UoM yang terlihat pada data Material antara lain:

```text
SHEET
KGM
PCS
ROLL
```

Ukuran Material untuk tahap awal tetap dapat disimpan sebagai:

```text
size
```

dalam bentuk string karena format existing cukup beragam.

Contoh:

```text
0.25 X 640 X 1480
2.0 X 125 X C
1.0 X 293 X C
```

Normalisasi ukuran menjadi thickness/width/length belum diperlukan sampai kebutuhan bisnisnya jelas.

---

# 7. WIP

Sumber:

```text
Sheet: master WIP
```

Kolom Excel:

```text
WIP Part #
WIP Part Name
WIP Model
WIP UoM
```

Mapping:

| Excel | ERP |
|---|---|
| WIP Part # | `part_number` |
| WIP Part Name | `part_name` |
| WIP Model | `model` |
| WIP UoM | `uom_id` |
| - | `part_type = WIP` |

Contoh:

```text
Part Number : AAN30056405-WIP1
Part Name   : Draw
Model       : BASE ASSEMBLY,COMPRESSOR VT 12
UoM         : PCS
Type        : WIP
```

Contoh urutan Part WIP yang terlihat:

```text
AAN30056405-WIP1  Draw
AAN30056405-WIP2  Trimming
AAN30056405-WIP3  Trimming
AAN30056405-WIP4  Bending
AAN30056405-WIP5  Bending
AAN30056405-WIP6  Assembly with screw
AAN30056405-WIP7  Assembly Pin
```

Catatan penting:

- WIP tetap dianggap sebagai Part.
- `WIP Part #` menjadi Part Number.
- Nama seperti `Draw`, `Trimming`, atau `Bending` saat ini disimpan sebagai nama WIP sesuai Excel.
- Relasi WIP dengan proses produksi belum dibahas di tahap Master Data ini.
- Di Excel terlihat kemungkinan Part WIP yang muncul lebih dari sekali. Saat migrasi nanti perlu dilakukan deduplication berdasarkan `part_number`.

---

# 8. UOM Master

Karena UoM digunakan pada FG, Material, dan WIP, UoM dibuat sebagai Master tersendiri.

```text
uoms
-------------------------
id
code
name
is_active
```

Contoh data awal yang ditemukan:

```text
PCS
KGM
SHEET
ROLL
```

Relasi:

```text
uoms
  │
  └──< parts
```

Tujuannya supaya tidak muncul variasi penulisan seperti:

```text
KG
KGM
KGS
Kilogram
```

untuk konsep satuan yang seharusnya sama tanpa keputusan bisnis yang jelas.

---

# 9. Material Substitute

Sumber:

```text
Sheet: master substitute
```

Kolom Excel:

```text
Material Name
Material Model
Material Part #
Material Size
Subs Part #
Sub Part Size
Sub Part Satuan
Supplier
Material Group
Source
```

Contoh:

```text
Main Material Part :
BPSH0256401335

Substitute:
VSYGIBPOMG8XS
CYXGIBPOMG8XS
KHRGIBPOMG8XS
KHCGIBPOMG8XS
```

Artinya satu Material Part dapat mempunyai lebih dari satu Substitute Part.

Struktur yang direkomendasikan:

```text
part_substitutes
--------------------------------
id
part_id
substitute_part_id
supplier_id
material_group
source
is_active
created_at
updated_at
```

Relasi:

```text
Part A
  │
  ├── Substitute Part A1
  ├── Substitute Part A2
  └── Substitute Part A3
```

## Substitute Part

`Subs Part #` tetap dianggap sebagai Part Number.

Artinya substitute sebaiknya juga diregistrasikan pada:

```text
parts
```

bukan hanya disimpan sebagai text di tabel relasi.

Data seperti:

```text
Sub Part Size
Sub Part Satuan
```

menjadi atribut dari Substitute Part.

Dengan begitu:

```text
part_substitutes
```

hanya bertugas menjelaskan hubungan:

```text
Part utama
    ↓
dapat diganti dengan
    ↓
Part substitute
```

---

# 10. Supplier Master

Supplier muncul pada sheet:

```text
master substitute
```

Contoh:

```text
SYSTEEL VINA JSC
TAIZHOU YONGXIN METAL CO.,LTD
HWI-RIM STEEL CO., LTD
HEE COMPANY CO.,LTD
BT INTERNATIONAL CO., LTD
```

Karena Supplier digunakan berulang, Supplier tidak boleh disimpan sebagai string bebas pada setiap relasi.

Master:

```text
suppliers
-------------------------
id
supplier_code
supplier_name
is_active
created_at
updated_at
```

Untuk tahap pertama kita **belum menambahkan**:

```text
address
phone
email
tax_number
contact_person
```

karena field tersebut belum terdapat di Excel.

Field tersebut bisa ditambahkan kemudian setelah kebutuhan bisnis Supplier dibahas.

---

# 11. Source

Pada sheet `master substitute` terdapat field:

```text
Source
```

Contoh yang terlihat:

```text
Import
```

Untuk tahap awal belum perlu membuat master terpisah apabila variasinya sedikit.

Bisa disimpan pada relasi substitute:

```text
part_substitutes.source
```

Keputusan apakah `Source` nantinya menjadi master sendiri ditunda sampai seluruh nilai existing diperiksa dan kebutuhan bisnisnya jelas.

---

# 12. Material Group

Pada sheet `master substitute` terdapat:

```text
Material Group
```

Contoh:

```text
STEEL IN SHEET
```

Untuk desain awal terdapat dua pilihan:

```text
A. disimpan sebagai string material_group
B. dinormalisasi menjadi material_groups
```

Untuk Master Data v0.1, direkomendasikan **belum memaksa normalisasi**.

Tetap simpan informasi dari Excel terlebih dahulu:

```text
material_group
```

Setelah business meaning dan daftar group divalidasi, baru diputuskan apakah menjadi master sendiri.

---

# 13. Machine Master

Sumber:

```text
Sheet: master machine
```

Kolom:

```text
No.
Machine Name
Process Name
FG Name
FG Model
FG Part No.
Parent Part No.
Parent Part Name
```

Perlu dibedakan antara:

```text
Machine Master
```

dan:

```text
mapping Machine terhadap Process / Part
```

Machine Master hanya menyimpan identitas Machine.

Struktur awal:

```text
machines
-------------------------
id
machine_code
machine_name
is_active
created_at
updated_at
```

Contoh:

```text
TPL COMP BASE
ASSY. TPL COMP BASE 1
```

`No.` dari Excel tidak langsung dianggap sebagai primary key ERP.

ERP menggunakan internal ID sendiri.

---

# 14. Process Master

`Process Name` pada sheet `master machine` dipisahkan menjadi Master Process.

Struktur:

```text
processes
-------------------------
id
process_code
process_name
is_active
created_at
updated_at
```

Contoh:

```text
Press
Assembly
```

Process berbeda dengan nama WIP.

Contoh:

```text
Process : Press

WIP:
- Draw
- Trimming
- Bending
```

Hubungan detail Process, Machine, FG dan WIP belum difinalisasi pada dokumen ini karena sudah mulai masuk area Routing/Engineering.

---

# 15. Machine–Process Mapping

Dari Excel terlihat bahwa Machine dan Process memiliki hubungan.

Untuk menjaga data Master tetap bersih dapat disiapkan:

```text
machine_processes
-------------------------
id
machine_id
process_id
```

Tetapi mapping berikut:

```text
Machine
+
Process
+
FG
+
Parent WIP
```

belum dimasukkan sebagai Master murni.

Data tersebut nantinya akan dievaluasi pada pembahasan:

```text
Routing / Engineering
```

karena sudah mendeskripsikan bagaimana Part tertentu diproduksi.

---

# 16. Struktur Database Master Data v0.1

Struktur sementara:

```text
part_types
    │
    └────< parts >──── uoms
              │
              │
              └────< part_substitutes >──── suppliers


machines
    │
    └────< machine_processes >──── processes
```

Tabel:

```text
part_types
parts
uoms

suppliers
part_substitutes

machines
processes
machine_processes
```

---

# 17. Struktur Menu ERP — Master Data

Menu awal dapat dibuat:

```text
Master Data
│
├── Part
│   ├── All Parts
│   ├── Finished Goods
│   ├── Materials
│   └── WIP
│
├── Material Substitute
├── Supplier
├── Machine
├── Process
└── UOM
```

`Finished Goods`, `Materials`, dan `WIP` bukan tabel database terpisah.

Ketiganya hanya view/filter dari:

```text
parts
```

berdasarkan:

```text
part_type
```

---

# 18. Aturan Dasar Data

## Part

```text
part_number
```

harus unique.

Nama Part:

```text
part_name
```

tidak harus unique.

## Substitute

Satu Part dapat memiliki banyak Substitute.

```text
1 Part
   ↓
many substitutes
```

Satu Substitute harus mempunyai identitas Part sendiri.

## Supplier

Supplier direferensikan menggunakan `supplier_id`, bukan nama supplier berupa string bebas.

## Machine

Machine Name tidak digunakan sebagai foreign key.

Gunakan:

```text
machine_id
```

## Process

Process direferensikan menggunakan:

```text
process_id
```

---

# 19. Data Cleaning Sebelum Import

Sebelum data Excel dimigrasikan ke ERP perlu pengecekan:

```text
1. Duplicate Part Number
2. Duplicate WIP Part Number
3. Empty UoM
4. Konsistensi UoM
5. Duplicate Supplier Name
6. Duplicate Machine Name
7. Duplicate Process Name
8. Substitute Part yang belum terdaftar sebagai Part
9. Whitespace / typo pada Part Number
10. Konsistensi penamaan Model
```

Data tidak langsung di-import mentah ke production database.

---

# 20. Yang Belum Dibahas

Dokumen ini sengaja belum membahas:

```text
BOM
Routing
Incoming
Purchasing
Inventory
Stock
Warehouse
Planning / MRP
Production Order
Production Result
QC
Outgoing
Customer
Finance
Accounting
HR
```

Semua itu dibahas setelah Master Data selesai disepakati.

---

# 21. Keputusan Sementara

Keputusan yang sudah disepakati:

```text
✓ Menggunakan istilah Part, bukan Item
✓ FG adalah Part
✓ Material adalah Part
✓ WIP adalah Part
✓ Substitute juga direpresentasikan sebagai Part
✓ Part Number menjadi business key utama
✓ Supplier dibuat Master
✓ Machine dibuat Master
✓ Process dibuat Master
✓ UOM dibuat Master
✓ BOM belum dibahas
✓ Routing belum dibahas
✓ Scope tetap mengikuti Excel existing
```

---

# 22. Status

```text
Project:
Manufacturing ERP

Phase:
Brainstorming

Current Domain:
Project Starter + Master Data

Version:
v0.3

Next Discussion:
Part Master detail dan rules
```
