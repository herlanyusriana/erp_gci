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
    source: 'local' | 'import' | null;
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

export interface Location {
    id: number;
    code: string;
    name: string;
    type?: 'storage' | 'machine' | 'delivery_prep' | null;
    is_active: boolean;
}

export interface PartSubstitute {
    id: number;
    part_id: number;
    substitute_part_id: number;
    supplier_id: number | null;
    material_group: string | null;
    material_group_label?: string | null;
    size: string | null;
    packing_type: string | null;
    source: string | null;
    is_active: boolean;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name'> & { model?: string | null; size?: string | null } | null;
    substitute_part?: (Pick<Part, 'id' | 'part_number' | 'part_name'> & {
        model?: string | null;
        size?: string | null;
        uom?: Pick<Uom, 'id' | 'code'> | null;
    }) | null;
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
    output_type?: 'normal' | 'shared' | 'byproduct' | null;
    tact_time_seconds?: number | null;
    qdc_minutes?: number | null;
    parent_part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    child_part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
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
    size: string | null;
    qty: number;
    arrived_qty?: number;
    remaining_qty?: number;
    unit: string | null;
    price: number | null;
    notes: string | null;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
}

export interface PurchaseOrder {
    id: number;
    po_no: string;
    supplier_id: number | null;
    status: string;
    currency: string | null;
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
    material_group_label?: string | null;
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
    part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    arrival?: {
        id: number;
        arrival_no?: string;
        arrival_type?: ArrivalType;
        is_local?: boolean;
        invoice_no: string | null;
        delivery_note_no?: string | null;
        contract_no?: string | null;
        reference_no?: string | null;
        pen_no?: string | null;
        pen_date?: string | null;
        aju_no?: string | null;
        aju_date?: string | null;
        po_no?: string | null;
        supplier?: Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'> | null;
        subcontractor?: Pick<Subcontractor, 'id' | 'subcontractor_code' | 'subcontractor_name'> | null;
        customer?: { id: number; customer_code: string; customer_name: string } | null;
        purchase_order?: { id: number; po_no: string } | null;
    } | null;
    receives?: IncomingReceive[];
    receives_count?: number;
}

export type ArrivalType = 'import' | 'local' | 'osp_gci' | 'osp_customer';

export interface Subcontractor {
    id: number;
    subcontractor_code: string;
    subcontractor_name: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    contact_person: string | null;
    npwp: string | null;
    bc_facility: string | null;
    process_notes: string | null;
    is_active: boolean;
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
        inspected_at?: string | null;
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
    subcontractor_id?: number | null;
    customer_id?: number | null;
    purchase_order_id: number | null;
    trucking_company_id: number | null;
    is_local: boolean;
    arrival_type?: ArrivalType;
    vessel: string | null;
    etd: string | null;
    eta: string | null;
    eta_gci: string | null;
    bill_of_lading: string | null;
    pen_no: string | null;
    pen_date: string | null;
    aju_no: string | null;
    aju_date?: string | null;
    price_term: string | null;
    hs_code: string | null;
    port_of_loading: string | null;
    country: string | null;
    currency: string | null;
    notes: string | null;
    remarks?: string | null;
    delivery_note_no?: string | null;
    contract_no?: string | null;
    reference_no?: string | null;
    status: string;
    iqc_status?: string;
    iqc_passed_at?: string | null;
    created_at?: string;
    items_count?: number;
    remaining_qty?: number;
    pending_items_count?: number;
    supplier?: Pick<Supplier, 'id' | 'supplier_code' | 'supplier_name'> | null;
    subcontractor?: Pick<Subcontractor, 'id' | 'subcontractor_code' | 'subcontractor_name'> | null;
    customer?: { id: number; customer_code: string; customer_name: string } | null;
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
    bundle_count?: number | null;
    bundle_qty: number | null;
    bundle_unit: string | null;
    packing_count?: number | null;
    packing_type?: string | null;
    packing_qty?: number | null;
    net_weight: number | null;
    gross_weight: number | null;
    qc_status: string | null;
    truck_no: string | null;
    driver_name?: string | null;
    location_code: string | null;
    invoice_no: string | null;
    ata_date: string | null;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
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
    part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
}

export interface MachineCycleTime {
    id: number;
    machine_id: number;
    part_id: number;
    cycle_time_seconds: number;
    qdc_minutes?: number | null;
    is_active: boolean;
    machine?: Pick<Machine, 'id' | 'machine_code' | 'machine_name'> | null;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
}

export interface MachineCycleTimeMatrixRow {
    part_id: number;
    part_number: string | null;
    part_name: string | null;
    model: string | null;
    fg_part_number: string | null;
    fg_part_name: string | null;
    fg_model: string | null;
    process_name: string | null;
    cycle_time_seconds: number | null;
    qdc_minutes: number | null;
    is_active: boolean;
}

export interface IncomingReceivePacking {
    id: number;
    receive_id: number;
    packing_no: string | null;
    sequence: number;
    qty: number;
    uom: string | null;
    net_weight: number | null;
}

export interface PartStock {
    id: number;
    part_id: number;
    tag: string | null;
    qty: number;
    qty_unit: string | null;
    location_code?: string | null;
    price: number | null;
    remarks: string | null;
    booked_qty?: number;
    avail_qty?: number;
    part?: (Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> & { part_type?: PartType | null }) | null;
    receive?: (Pick<IncomingReceive, 'id' | 'invoice_no' | 'location_code' | 'packing_count' | 'packing_type' | 'bundle_unit' | 'net_weight'> & {
        packings?: IncomingReceivePacking[];
        arrival_item?: (Pick<IncomingArrivalItem, 'id' | 'arrival_id'> & {
            arrival?: (Pick<IncomingArrival, 'id' | 'invoice_no'> & {
                arrival_no?: string;
                arrival_type?: ArrivalType;
                is_local?: boolean;
                delivery_note_no?: string | null;
                contract_no?: string | null;
                supplier?: Pick<Supplier, 'id' | 'supplier_name'> | null;
                subcontractor?: Pick<Subcontractor, 'id' | 'subcontractor_name'> | null;
                customer?: { id: number; customer_name: string } | null;
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
    parent_part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    child_part?: (Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> & { part_substitutes?: PartSubstitute[] }) | null;
    selected_part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    allocations?: WorkOrderItemAllocation[];
}

export interface WorkOrderItemAllocation {
    id: number;
    work_order_item_id: number;
    part_id: number;
    qty: number;
    part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
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
    part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
}

export interface MaterialIssue {
    id: number;
    issue_no: string;
    work_order_id: number;
    issue_date: string;
    created_at?: string;
    issued_by: number | null;
    operator_name?: string | null;
    received_by: string | null;
    status: string;
    notes: string | null;
    items_count?: number;
    work_order?: (Pick<WorkOrder, 'id' | 'wo_no' | 'part_id'> & { part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null }) | null;
    issuer?: Pick<User, 'id' | 'name'> | null;
    items?: MaterialIssueItem[];
}

export interface MaterialIssueSummary {
    issue_count: number;
    work_order_count: number;
    tag_count: number;
    qty_by_uom: Array<{ uom: string; qty: number }>;
}

export interface MaterialIssuePostedEvent {
    issue_id: number;
    issue_no: string;
    work_order_id: number;
    work_order_no: string | null;
    operator_id: number | null;
    operator_name: string | null;
    received_by: string | null;
    status: string;
    issue_date: string;
    item_count: number;
    tag_count: number;
    qty_by_uom: Record<string, number>;
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
    results_count?: number;
    fg_produced?: number;
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
    sequence_d: number;
    sequence_d1: number;
    sequence_d2: number;
    step_sequence?: number | null;
    target_d: number | null;
    target_d1: number | null;
    target_d2: number | null;
    avail_qty: number;
    cycle_time_seconds?: number | null;
    qdc_minutes?: number | null;
    estimated_seconds?: number | null;
    machine?: Pick<Machine, 'id' | 'machine_code' | 'machine_name'> | null;
    process?: Process | null;
    work_order?: Pick<WorkOrder, 'id' | 'wo_no' | 'part_id' | 'qty' | 'status'> & {
        part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    } | null;
    fg_part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    input_part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    wip_part?: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
}

export interface ProductionPlanDaySlot {
    id: number;
    sequence: number;
    qty: number;
    apply_qdc: boolean;
    start_time: string | null;
    end_time: string | null;
    qdc_applied_minutes: number;
    duration_seconds: number | null;
    is_overload: boolean;
    machine_id?: number;
    machine_name?: string | null;
}

export interface ProductionPlanMachineRow {
    work_order_id: number;
    origin_machine_id?: number;
    origin_machine_name?: string | null;
    is_reassigned?: boolean;
    wo_no: string;
    wo_status: string;
    wo_qty: number;
    remaining_qty: number;
    planned_total: number;
    avail_qty: number;
    has_upstream?: boolean;
    eligible_qty?: number;
    day_caps?: { d: number; d1: number; d2: number };
    fg_part: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    input_part: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    wip_part: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    process_name: string | null;
    cycle_time_seconds: number | null;
    qdc_minutes: number | null;
    days: {
        d: ProductionPlanDaySlot | null;
        d1: ProductionPlanDaySlot | null;
        d2: ProductionPlanDaySlot | null;
    };
}

export interface ProductionPlanBoardMachine {
    id: number;
    machine_code: string;
    machine_name: string;
    sequence: number | null;
    avail_wo_count: number;
    initial_qdc: { d: boolean; d1: boolean; d2: boolean };
    shift_counts: { d: number; d1: number; d2: number };
    rollover_target?: {
        id: number;
        machine_code: string;
        machine_name: string;
    } | null;
    sync_downstream?: {
        d: boolean;
        d1: boolean;
        d2: boolean;
    };
    planned_rows: ProductionPlanMachineRow[];
    modal_rows: ProductionPlanMachineRow[];
    fg_part_ids: number[];
}

export interface AvailWorkOrderSummary {
    id: number;
    wo_no: string;
    status: string;
    qty: number;
    planned_date: string | null;
    part: Pick<Part, 'id' | 'part_number' | 'part_name' | 'model'> | null;
    machines: Array<{
        machine_id: number;
        machine_name: string;
        remaining_qty: number;
        planned_qty: number;
        avail_qty: number;
    }>;
    planned_machines_count: number;
    total_machines_count: number;
}
