-- =====================================================================================================
-- Struktur skema `public` database produksi (Supabase, PostgreSQL 17), 19 tabel. HANYA struktur, tanpa data.
-- Diambil dari katalog database asli pada 22 Oktober 2026 saat pindah ke proyek Supabase baru.
--
-- Fungsi: acuan / pemulihan darurat. Instalasi normal tetap memakai `php artisan migrate`, yang sejak
-- migrasi 2026_10_17_000002 menghasilkan struktur yang identik dengan file ini.
-- Nama file sengaja bukan `pgsql-schema.sql` agar tidak dimuat otomatis oleh `php artisan migrate`.
-- Bila struktur database berubah (migrasi baru), perbarui file ini.
--
-- Catatan: employees.status punya DEFAULT 'Aktif' yang tidak lolos CHECK (Active / Resigned);
-- aplikasi selalu mengisi status sendiri, jadi default ini tidak pernah dipakai.
-- =====================================================================================================


CREATE SEQUENCE public.a1_routes_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.a2_assessments_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.a2_criteria_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.a2_participants_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.a2_units_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.assessments_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.criteria_descriptions_id_desc_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.failed_jobs_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.hrd_sanctions_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.jobs_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE public.migrations_id_seq AS integer INCREMENT BY 1 MINVALUE 1 MAXVALUE 2147483647 START WITH 1 CACHE 1;

CREATE SEQUENCE public.users_id_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE TABLE public.a1_routes (
    id bigint DEFAULT nextval('a1_routes_id_seq'::regclass) NOT NULL,
    employee_id character varying(255),
    department character varying(255),
    jabatans json,
    assessor_id bigint NOT NULL,
    approver_id bigint NOT NULL,
    note character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE TABLE public.a2_assessments (
    id bigint DEFAULT nextval('a2_assessments_id_seq'::regclass) NOT NULL,
    unit_id bigint NOT NULL,
    employee_id character varying(255) NOT NULL,
    period character varying(255) NOT NULL,
    scores json NOT NULL,
    criteria_snapshot json NOT NULL,
    final_score numeric(5,2) NOT NULL,
    status character varying(255) NOT NULL,
    notes json,
    created_by character varying(255),
    approved_by character varying(255),
    rejection_note text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE TABLE public.a2_criteria (
    id bigint DEFAULT nextval('a2_criteria_id_seq'::regclass) NOT NULL,
    unit_id bigint NOT NULL,
    sort smallint DEFAULT '0'::smallint NOT NULL,
    group_zh character varying(255),
    group_id character varying(255),
    name_zh text,
    name_id text NOT NULL,
    weight numeric(5,2) NOT NULL,
    target text,
    remark text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE TABLE public.a2_participants (
    id bigint DEFAULT nextval('a2_participants_id_seq'::regclass) NOT NULL,
    unit_id bigint NOT NULL,
    employee_id character varying(255) NOT NULL,
    assessor_id bigint,
    approver_id bigint,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE TABLE public.a2_units (
    id bigint DEFAULT nextval('a2_units_id_seq'::regclass) NOT NULL,
    name character varying(255) NOT NULL,
    department character varying(255),
    sort smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE TABLE public.app_settings (
    key character varying(100) NOT NULL,
    value json NOT NULL,
    updated_by character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE TABLE public.assessments (
    id bigint DEFAULT nextval('assessments_id_seq'::regclass) NOT NULL,
    employee_id character varying(255) NOT NULL,
    period character varying(255) NOT NULL,
    score_work_quality integer,
    score_discipline integer DEFAULT 0,
    score_attitude integer,
    final_score numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(255) DEFAULT 'Draft'::character varying NOT NULL,
    rejection_note text,
    created_by character varying(255),
    verified_by character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    approved_by character varying(255),
    score_attendance integer,
    score_tardiness integer,
    score_efficiency integer,
    score_safety integer,
    edited_by character varying(50),
    edited_at timestamp(0) without time zone,
    notes json,
    hrd_imported_at timestamp(0) without time zone,
    sanction_locked_at timestamp(0) without time zone,
    sanction_locked_by character varying(50)
);

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);

CREATE TABLE public.criteria_descriptions (
    id_desc bigint DEFAULT nextval('criteria_descriptions_id_desc_seq'::regclass) NOT NULL,
    department character varying(255) NOT NULL,
    desc1 text,
    desc2 text,
    desc3 text,
    desc4 text,
    desc5 text,
    desc6 text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    sub_department character varying(255),
    section character varying(255)
);

CREATE TABLE public.employees (
    id_employee character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    department character varying(255) NOT NULL,
    "position" character varying(255) NOT NULL,
    grade character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'Aktif'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    sub_department character varying(255),
    section character varying(255),
    labor_type character varying(4),
    assessment_level smallint
);

CREATE TABLE public.failed_jobs (
    id bigint DEFAULT nextval('failed_jobs_id_seq'::regclass) NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE public.hrd_sanctions (
    id bigint DEFAULT nextval('hrd_sanctions_id_seq'::regclass) NOT NULL,
    source_key character varying(191) NOT NULL,
    employee_id character varying(30) NOT NULL,
    period character varying(7) NOT NULL,
    sanction_date date NOT NULL,
    letter_no character varying(80),
    sanction_type character varying(30) NOT NULL,
    case_text text,
    case_summary character varying(450) NOT NULL,
    article character varying(150),
    name_in_file character varying(150),
    section_in_file character varying(60),
    imported_by character varying(50),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);

CREATE TABLE public.jobs (
    id bigint DEFAULT nextval('jobs_id_seq'::regclass) NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);

CREATE TABLE public.migrations (
    id integer DEFAULT nextval('migrations_id_seq'::regclass) NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);

CREATE TABLE public.users (
    id bigint DEFAULT nextval('users_id_seq'::regclass) NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255),
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    username character varying(255),
    role character varying(255) DEFAULT 'assistant'::character varying NOT NULL,
    department character varying(255),
    must_change_password boolean DEFAULT false NOT NULL,
    section character varying(255),
    nik character varying(30),
    grade smallint,
    groups text,
    stage smallint,
    job_title character varying(255)
);

ALTER SEQUENCE public.a1_routes_id_seq OWNED BY public.a1_routes.id;

ALTER SEQUENCE public.a2_assessments_id_seq OWNED BY public.a2_assessments.id;

ALTER SEQUENCE public.a2_criteria_id_seq OWNED BY public.a2_criteria.id;

ALTER SEQUENCE public.a2_participants_id_seq OWNED BY public.a2_participants.id;

ALTER SEQUENCE public.a2_units_id_seq OWNED BY public.a2_units.id;

ALTER SEQUENCE public.assessments_id_seq OWNED BY public.assessments.id;

ALTER SEQUENCE public.criteria_descriptions_id_desc_seq OWNED BY public.criteria_descriptions.id_desc;

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;

ALTER SEQUENCE public.hrd_sanctions_id_seq OWNED BY public.hrd_sanctions.id;

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;

ALTER TABLE public.a2_assessments ADD CONSTRAINT a2_assessments_employee_id_period_unique UNIQUE (employee_id, period);

ALTER TABLE public.a2_participants ADD CONSTRAINT a2_participants_employee_id_unique UNIQUE (employee_id);

ALTER TABLE public.a2_units ADD CONSTRAINT a2_units_name_unique UNIQUE (name);

ALTER TABLE public.assessments ADD CONSTRAINT assessments_employee_period_unique UNIQUE (employee_id, period);

ALTER TABLE public.failed_jobs ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);

ALTER TABLE public.hrd_sanctions ADD CONSTRAINT hrd_sanctions_source_key_unique UNIQUE (source_key);

ALTER TABLE public.users ADD CONSTRAINT users_email_unique UNIQUE (email);

ALTER TABLE public.users ADD CONSTRAINT users_username_unique UNIQUE (username);

ALTER TABLE public.a1_routes ADD CONSTRAINT a1_routes_pkey PRIMARY KEY (id);

ALTER TABLE public.a2_assessments ADD CONSTRAINT a2_assessments_pkey PRIMARY KEY (id);

ALTER TABLE public.a2_criteria ADD CONSTRAINT a2_criteria_pkey PRIMARY KEY (id);

ALTER TABLE public.a2_participants ADD CONSTRAINT a2_participants_pkey PRIMARY KEY (id);

ALTER TABLE public.a2_units ADD CONSTRAINT a2_units_pkey PRIMARY KEY (id);

ALTER TABLE public.app_settings ADD CONSTRAINT app_settings_pkey PRIMARY KEY (key);

ALTER TABLE public.assessments ADD CONSTRAINT assessments_pkey PRIMARY KEY (id);

ALTER TABLE public.cache ADD CONSTRAINT cache_pkey PRIMARY KEY (key);

ALTER TABLE public.cache_locks ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);

ALTER TABLE public.criteria_descriptions ADD CONSTRAINT criteria_descriptions_pkey PRIMARY KEY (id_desc);

ALTER TABLE public.employees ADD CONSTRAINT employees_pkey PRIMARY KEY (id_employee);

ALTER TABLE public.failed_jobs ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);

ALTER TABLE public.hrd_sanctions ADD CONSTRAINT hrd_sanctions_pkey PRIMARY KEY (id);

ALTER TABLE public.job_batches ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);

ALTER TABLE public.jobs ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);

ALTER TABLE public.migrations ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);

ALTER TABLE public.password_reset_tokens ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);

ALTER TABLE public.sessions ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);

ALTER TABLE public.users ADD CONSTRAINT users_pkey PRIMARY KEY (id);

ALTER TABLE public.assessments ADD CONSTRAINT assessments_status_check CHECK (((status)::text = ANY ((ARRAY['Draft'::character varying, 'Pending'::character varying, 'Verified'::character varying, 'Approved'::character varying, 'Rejected'::character varying])::text[])));

ALTER TABLE public.employees ADD CONSTRAINT employees_status_check CHECK (((status)::text = ANY ((ARRAY['Active'::character varying, 'Resigned'::character varying])::text[])));

CREATE INDEX a1_routes_department_index ON public.a1_routes USING btree (department);

CREATE INDEX a1_routes_employee_id_index ON public.a1_routes USING btree (employee_id);

CREATE INDEX a2_assessments_status_period_index ON public.a2_assessments USING btree (status, period);

CREATE INDEX a2_assessments_unit_id_index ON public.a2_assessments USING btree (unit_id);

CREATE INDEX a2_criteria_unit_id_sort_index ON public.a2_criteria USING btree (unit_id, sort);

CREATE INDEX a2_participants_approver_id_index ON public.a2_participants USING btree (approver_id);

CREATE INDEX a2_participants_assessor_id_index ON public.a2_participants USING btree (assessor_id);

CREATE INDEX a2_participants_unit_id_index ON public.a2_participants USING btree (unit_id);

CREATE INDEX assessments_period_status_index ON public.assessments USING btree (period, status);

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);

CREATE INDEX employees_section_index ON public.employees USING btree (section);

CREATE INDEX employees_status_department_index ON public.employees USING btree (status, department);

CREATE INDEX hrd_sanctions_employee_id_index ON public.hrd_sanctions USING btree (employee_id);

CREATE INDEX hrd_sanctions_period_employee_id_index ON public.hrd_sanctions USING btree (period, employee_id);

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);

ALTER TABLE public.a1_routes ADD CONSTRAINT a1_routes_approver_id_foreign FOREIGN KEY (approver_id) REFERENCES users(id);

ALTER TABLE public.a1_routes ADD CONSTRAINT a1_routes_assessor_id_foreign FOREIGN KEY (assessor_id) REFERENCES users(id);

ALTER TABLE public.a2_assessments ADD CONSTRAINT a2_assessments_unit_id_foreign FOREIGN KEY (unit_id) REFERENCES a2_units(id);

ALTER TABLE public.a2_criteria ADD CONSTRAINT a2_criteria_unit_id_foreign FOREIGN KEY (unit_id) REFERENCES a2_units(id) ON DELETE CASCADE;

ALTER TABLE public.a2_participants ADD CONSTRAINT a2_participants_approver_id_foreign FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE public.a2_participants ADD CONSTRAINT a2_participants_assessor_id_foreign FOREIGN KEY (assessor_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE public.a2_participants ADD CONSTRAINT a2_participants_unit_id_foreign FOREIGN KEY (unit_id) REFERENCES a2_units(id) ON DELETE CASCADE;

ALTER TABLE public.assessments ADD CONSTRAINT assessments_employee_id_foreign FOREIGN KEY (employee_id) REFERENCES employees(id_employee) ON DELETE CASCADE;

ALTER TABLE public.a1_routes ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.a2_assessments ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.a2_criteria ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.a2_participants ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.a2_units ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.app_settings ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.assessments ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.cache ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.cache_locks ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.criteria_descriptions ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.employees ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.failed_jobs ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.hrd_sanctions ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.job_batches ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.jobs ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.migrations ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.password_reset_tokens ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.sessions ENABLE ROW LEVEL SECURITY;

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
