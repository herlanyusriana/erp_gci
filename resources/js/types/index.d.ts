export type SupportedLocale = 'id' | 'en' | 'ko';

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    is_active?: boolean;
}

export interface AppSettings {
    companyName?: string;
    applicationName?: string;
    timezone?: string;
    dateFormat?: string;
    currency?: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    locale: SupportedLocale;
    flash: { success?: string; error?: string };
    auth: {
        user: User;
        roles: string[];
        permissions: string[];
    };
    appSettings: AppSettings;
};

export interface PartType {
    id: number;
    code: string;
    name: string;
}

export interface Uom {
    id: number;
    code: string;
    name: string | null;
    is_active: boolean;
}

export interface Part {
    id: number;
    part_number: string;
    part_name: string;
    hs_code?: string | null;
    part_type?: PartType | null;
    uom?: Uom | null;
    model?: string | null;
    size?: string | null;
    nett_weight?: number | null;
    is_active: boolean;
    remarks?: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface Supplier {
    id: number;
    supplier_code: string;
    supplier_name: string;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    contact_person?: string | null;
    bank_account?: string | null;
    signature_path?: string | null;
    signature_url?: string | null;
    is_active: boolean;
}

export interface TruckingCompany {
    id: number;
    company_code: string;
    company_name: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    contact_person: string | null;
    is_active: boolean;
    created_at?: string;
}

export interface Machine {
    id: number;
    machine_code: string;
    machine_name: string;
    sequence?: number | null;
    is_active: boolean;
}

export interface Process {
    id: number;
    process_code: string;
    process_name: string;
    is_active: boolean;
}

export interface PartSubstitute {
    id: number;
    part_id: number;
    substitute_part_id: number;
    supplier_id: number | null;
    material_group: string | null;
    source: string | null;
    is_active: boolean;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    substitute_part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    supplier?: Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'> | null;
}

export interface ConfigMaster {
    id: number;
    group: string;
    key: string;
    value: string | null;
    data_type: string;
    description: string | null;
    is_active: boolean;
}

export interface Permission {
    id: number;
    name: string;
    module: string | null;
    label: string | null;
}

export interface Role {
    id: number;
    name: string;
    label: string | null;
    description: string | null;
    users_count?: number;
    permissions?: Permission[];
}

export interface ManagedUser {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    created_at?: string;
    deleted_at?: string | null;
    roles?: Array<{ id: number; name: string; label: string | null }>;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

export interface BomItem {
    id: number;
    bom_id: number;
    sequence: number | null;
    process?: Process | null;
    machine?: Machine | null;
    parent_part_id: number | null;
    parent_part_name: string | null;
    parent_qty: number | null;
    parent_uom: string | null;
    child_part_id: number | null;
    child_part_name: string | null;
    size: string | null;
    child_qty: number | null;
    uom_rm: string | null;
    special_code: string | null;
    source: string | null;
    parent_part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    child_part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
}

export interface Bom {
    id: number;
    part_id: number;
    bom_no: number | null;
    version: string | null;
    is_active: boolean;
    items_count?: number;
    part?: (Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> & {
        part_type?: PartType | null;
        uom?: Uom | null;
    }) | null;
    items?: BomItem[];
}

export interface PurchaseOrderItem {
    id: number;
    purchase_order_id: number;
    part_id: number | null;
    qty: number;
    unit: string | null;
    price: number | null;
    notes: string | null;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
}

export interface PurchaseOrder {
    id: number;
    po_no: string;
    supplier_id: number | null;
    status: string;
    po_date: string | null;
    expected_date: string | null;
    notes: string | null;
    items_count?: number;
    supplier?: Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'> | null;
    items?: PurchaseOrderItem[];
}

export interface IncomingArrivalItem {
    id: number;
    arrival_id: number;
    part_id: number | null;
    material_group: string | null;
    size: string | null;
    qty_goods: number;
    unit_goods: string | null;
    qty_bundle: number | null;
    unit_bundle: string | null;
    weight_nett: number | null;
    unit_weight: string | null;
    weight_gross: number | null;
    price: number | null;
    total_price: number | null;
    is_foc: boolean;
    notes: string | null;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    receives?: IncomingReceive[];
}

export interface IncomingArrivalContainer {
    id: number;
    arrival_id: number;
    container_no: string;
    seal_code: string | null;
    size: string | null;
    inspection?: {
        id: number;
        status: string;
        seal_condition: string | null;
        container_condition: string | null;
        driver_name: string | null;
        notes: string | null;
    } | null;
}

export interface IncomingArrival {
    id: number;
    arrival_no: string;
    transaction_no: string | null;
    po_no: string | null;
    invoice_no: string | null;
    invoice_date: string | null;
    supplier_id: number | null;
    purchase_order_id: number | null;
    trucking_company_id: number | null;
    is_local: boolean;
    vessel: string | null;
    etd: string | null;
    eta: string | null;
    eta_gci: string | null;
    bill_of_lading: string | null;
    pen_no: string | null;
    pen_date: string | null;
    aju_no: string | null;
    price_term: string | null;
    hs_code: string | null;
    port_of_loading: string | null;
    country: string | null;
    currency: string | null;
    notes: string | null;
    status: string;
    created_at?: string;
    items_count?: number;
    remaining_qty?: number;
    pending_items_count?: number;
    supplier?: Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'> | null;
    purchase_order?: Pick<PurchaseOrder, 'id' | 'po_no'> | null;
    trucking?: Pick<TruckingCompany, 'id' | 'company_code' | 'company_name'> | null;
    items?: IncomingArrivalItem[];
    containers?: IncomingArrivalContainer[];
}

export interface IncomingReceive {
    id: number;
    arrival_item_id: number;
    part_id: number | null;
    tag: string | null;
    qty: number;
    qty_unit: string | null;
    bundle_qty: number | null;
    bundle_unit: string | null;
    net_weight: number | null;
    gross_weight: number | null;
    qc_status: string | null;
    truck_no: string | null;
    invoice_no: string | null;
    ata_date: string | null;
    arrival_item?: IncomingArrivalItem;
}

export interface PartPrice {
    id: number;
    supplier_id: number;
    part_id: number;
    price: number;
    currency: string;
    valid_from: string;
    is_active: boolean;
    supplier?: Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'> | null;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
}

export interface MachineCycleTime {
    id: number;
    machine_id: number;
    part_id: number;
    cycle_time_seconds: number;
    is_active: boolean;
    machine?: Pick<Machine, 'id' | 'machine_code' | 'machine_name'> | null;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
}

export interface PartStock {
    id: number;
    part_id: number;
    tag: string | null;
    qty: number;
    qty_unit: string | null;
    price: number | null;
    remarks: string | null;
    booked_qty?: number;
    avail_qty?: number;
    part?: (Pick<Part, 'id' | 'part_number' | 'part_name'> & { part_type?: PartType | null }) | null;
    receive?: (Pick<IncomingReceive, 'id' | 'invoice_no'> & {
        arrival_item?: (Pick<IncomingArrivalItem, 'id' | 'arrival_id'> & {
            arrival?: (Pick<IncomingArrival, 'id'> & {
                supplier?: Pick<Supplier, 'id' | 'supplier_name'> | null;
            }) | null;
        }) | null;
    }) | null;
}

export interface WorkOrderMachine {
    id: number;
    machine_code: string;
    machine_name: string;
}

export interface WorkOrderItem {
    id: number;
    work_order_id: number;
    sequence: number | null;
    process?: Process | null;
    machine?: Machine | null;
    process_id: number | null;
    machine_id: number | null;
    parent_part_id: number | null;
    parent_part_name: string | null;
    parent_qty: number | null;
    parent_uom: string | null;
    child_part_id: number | null;
    selected_part_id: number | null;
    child_part_name: string | null;
    size: string | null;
    child_qty: number | null;
    uom_rm: string | null;
    special_code: string | null;
    source: string | null;
    qty_required: number;
    qty_consumed: number;
    parent_part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    child_part?: (Pick<Part, 'id' | 'part_number' | 'part_name'> & { part_substitutes?: PartSubstitute[] }) | null;
    selected_part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    allocations?: WorkOrderItemAllocation[];
}

export interface WorkOrderItemAllocation {
    id: number;
    work_order_item_id: number;
    part_id: number;
    qty: number;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
}

export interface WorkOrderConsumption {
    id: number;
    work_order_id: number;
    work_order_item_id: number;
    part_stock_id: number | null;
    part_id: number | null;
    qty: number;
    uom: string | null;
}

export interface MaterialIssueItem {
    id: number;
    material_issue_id: number;
    work_order_item_id: number;
    part_id: number;
    tag: string | null;
    qty: number;
    uom: string | null;
    price: number | null;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
}

export interface MaterialIssue {
    id: number;
    issue_no: string;
    work_order_id: number;
    issue_date: string;
    issued_by: number | null;
    received_by: string | null;
    status: string;
    notes: string | null;
    items_count?: number;
    work_order?: (Pick<WorkOrder, 'id' | 'wo_no' | 'part_id'> & { part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null }) | null;
    issuer?: Pick<User, 'id' | 'name'> | null;
    items?: MaterialIssueItem[];
}

export interface WorkOrder {
    id: number;
    wo_no: string;
    part_id: number;
    qty: number;
    status: string;
    planned_date: string | null;
    released_at: string | null;
    completed_at: string | null;
    remarks: string | null;
    created_at?: string;
    items_count?: number;
    part?: (Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> & {
        part_type?: PartType | null;
        uom?: Uom | null;
    }) | null;
    items?: WorkOrderItem[];
    consumptions?: WorkOrderConsumption[];
}

export interface ProductionPlan {
    id: number;
    plan_date: string;
    notes: string | null;
}

export interface ProductionPlanItem {
    id: number;
    production_plan_id: number;
    machine_id: number | null;
    process_id?: number | null;
    work_order_id: number | null;
    fg_part_id: number | null;
    input_part_id: number | null;
    wip_part_id: number | null;
    sequence: number;
    step_sequence?: number | null;
    target_d: number | null;
    target_d1: number | null;
    target_d2: number | null;
    avail_qty: number;
    estimated_seconds?: number | null;
    machine?: Pick<Machine, 'id' | 'machine_code' | 'machine_name'> | null;
    process?: Process | null;
    work_order?: Pick<WorkOrder, 'id' | 'wo_no' | 'part_id' | 'qty' | 'status'> & {
        part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    } | null;
    fg_part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    input_part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
    wip_part?: Pick<Part, 'id' | 'part_number' | 'part_name'> | null;
}
