<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplements TestSchema with the tables the Filament plugin's
 * resources reference but the core CRM's TestSchema doesn't ship —
 * primarily auxiliary lookup, communication, and pipeline-conversion
 * tables exposed in v0.x of the plugin (Lunches, Meetings, Calls,
 * Deliveries, Purchase Orders, Files, lookup tables, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('laravel-crm.db_table_prefix');

        if (Schema::hasTable($prefix . 'field_options') && ! Schema::hasColumn($prefix . 'field_options', 'label')) {
            Schema::table($prefix . 'field_options', function (Blueprint $table) {
                $table->string('label')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'fields') && ! Schema::hasColumn($prefix . 'fields', 'handle')) {
            Schema::table($prefix . 'fields', function (Blueprint $table) {
                $table->string('handle')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'fields') && ! Schema::hasColumn($prefix . 'fields', 'required')) {
            Schema::table($prefix . 'fields', function (Blueprint $table) {
                $table->boolean('required')->default(false);
            });
        }

        if (Schema::hasTable($prefix . 'field_groups') && ! Schema::hasColumn($prefix . 'field_groups', 'system')) {
            Schema::table($prefix . 'field_groups', function (Blueprint $table) {
                $table->boolean('system')->default(false);
            });
        }

        if (Schema::hasTable($prefix . 'field_groups') && ! Schema::hasColumn($prefix . 'field_groups', 'handle')) {
            Schema::table($prefix . 'field_groups', function (Blueprint $table) {
                $table->string('handle')->nullable();
            });
        }

        // US-006: roles table patches (description / crm_role) for RoleResource parity with core
        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                if (! Schema::hasColumn('roles', 'description')) {
                    $table->string('description')->nullable();
                }
                if (! Schema::hasColumn('roles', 'crm_role')) {
                    $table->boolean('crm_role')->default(false);
                }
            });
        }

        if (! Schema::hasTable($prefix . 'industries')) {
            Schema::create($prefix . 'industries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'contact_types')) {
            Schema::create($prefix . 'contact_types', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'address_types')) {
            Schema::create($prefix . 'address_types', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'organization_types')) {
            Schema::create($prefix . 'organization_types', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'timezones')) {
            Schema::create($prefix . 'timezones', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('offset')->nullable();
                $table->string('diff_from_gtm')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'product_attributes')) {
            Schema::create($prefix . 'product_attributes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'pipeline_stage_probabilities')) {
            Schema::create($prefix . 'pipeline_stage_probabilities', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->string('name');
                $table->integer('percent')->default(0);
                $table->timestamps();
            });
        }

        // US-004: pipeline_stage_probabilities uses SoftDeletes on its model but the test-schema CREATE above
        // doesn't ship deleted_at. Add it idempotently so relation queries (e.g. from a PipelineStage's
        // belongsTo) don't hit "no such column: deleted_at" on the softDeletes global scope.
        if (Schema::hasTable($prefix . 'pipeline_stage_probabilities')
            && ! Schema::hasColumn($prefix . 'pipeline_stage_probabilities', 'deleted_at')) {
            Schema::table($prefix . 'pipeline_stage_probabilities', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // US-004: pipeline_stages column patches (description / color / pipeline_stage_probability_id)
        if (Schema::hasTable($prefix . 'pipeline_stages')) {
            Schema::table($prefix . 'pipeline_stages', function (Blueprint $table) use ($prefix) {
                if (! Schema::hasColumn($prefix . 'pipeline_stages', 'description')) {
                    $table->text('description')->nullable();
                }
                if (! Schema::hasColumn($prefix . 'pipeline_stages', 'color')) {
                    $table->string('color')->nullable();
                }
                if (! Schema::hasColumn($prefix . 'pipeline_stages', 'pipeline_stage_probability_id')) {
                    $table->unsignedBigInteger('pipeline_stage_probability_id')->nullable();
                }
            });
        }

        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->string('name');
                $table->boolean('personal_team')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'teams')) {
            Schema::create($prefix . 'teams', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('user_owner_id')->nullable();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'team_user')) {
            Schema::create($prefix . 'team_user', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('crm_team_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'calls')) {
            Schema::create($prefix . 'calls', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->string('name')->nullable();
                $table->nullableMorphs('callable');
                $table->text('description')->nullable();
                $table->dateTime('start_at')->nullable();
                $table->dateTime('finish_at')->nullable();
                $table->dateTime('called_at')->nullable();
                $table->boolean('completed')->default(false);
                $table->unsignedBigInteger('user_owner_id')->nullable();
                $table->unsignedBigInteger('user_assigned_id')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_deleted_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'meetings')) {
            Schema::create($prefix . 'meetings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->morphs('meetingable');
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->dateTime('start_at')->nullable();
                $table->dateTime('finish_at')->nullable();
                $table->string('location')->nullable();
                $table->boolean('completed')->default(false);
                $table->unsignedBigInteger('user_assigned_id')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'lunches')) {
            Schema::create($prefix . 'lunches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->morphs('lunchable');
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->dateTime('start_at')->nullable();
                $table->dateTime('finish_at')->nullable();
                $table->string('location')->nullable();
                $table->boolean('completed')->default(false);
                $table->unsignedBigInteger('user_assigned_id')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'deliveries')) {
            Schema::create($prefix . 'deliveries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->string('delivery_id')->nullable();
                $table->string('prefix')->nullable();
                $table->unsignedInteger('number')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('person_id')->nullable();
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->date('delivery_date')->nullable();
                $table->date('delivered_at')->nullable();
                $table->text('notes')->nullable();
                $table->string('status')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_assigned_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'delivery_products')) {
            Schema::create($prefix . 'delivery_products', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('delivery_id');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('order_product_id')->nullable();
                $table->decimal('quantity', 15, 3)->default(1);
                $table->text('comments')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'purchase_orders')) {
            Schema::create($prefix . 'purchase_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->string('purchase_order_id')->nullable();
                $table->string('prefix')->nullable();
                $table->unsignedInteger('number')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('person_id')->nullable();
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->date('issue_date')->nullable();
                $table->date('expected_at')->nullable();
                $table->date('delivered_at')->nullable();
                $table->unsignedBigInteger('currency')->nullable();
                $table->unsignedBigInteger('sub_total')->nullable();
                $table->unsignedBigInteger('discount')->nullable();
                $table->unsignedBigInteger('tax')->nullable();
                $table->unsignedBigInteger('total')->nullable();
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->text('terms')->nullable();
                $table->string('delivery_type')->nullable();
                $table->string('status')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_owner_id')->nullable();
                $table->unsignedBigInteger('user_assigned_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'purchase_order_lines')) {
            Schema::create($prefix . 'purchase_order_lines', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('purchase_order_id');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->decimal('quantity', 15, 3)->default(1);
                $table->decimal('unit_price', 15, 2)->nullable();
                $table->decimal('amount', 15, 2)->nullable();
                $table->string('currency')->nullable();
                $table->decimal('discount', 15, 2)->nullable();
                $table->decimal('tax', 15, 2)->nullable();
                $table->decimal('price', 15, 2)->nullable();
                $table->decimal('cost_per_unit', 15, 2)->nullable();
                $table->unsignedBigInteger('tax_rate_id')->nullable();
                // Base's create_laravel_crm_purchase_order_lines_table ships
                // both of these and PurchaseOrderService::create() writes
                // tax_amount on every line.
                $table->decimal('tax_rate')->nullable();
                $table->integer('tax_amount')->nullable();
                $table->unsignedBigInteger('order_product_id')->nullable();
                $table->text('comments')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'files')) {
            Schema::create($prefix . 'files', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->morphs('fileable');
                $table->string('file');
                $table->string('name')->nullable();
                $table->string('title')->nullable();
                $table->string('format')->nullable();
                $table->string('filesize')->nullable();
                $table->string('mime')->nullable();
                $table->string('disk')->default('local');
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_deleted_id')->nullable();
                $table->unsignedBigInteger('user_restored_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'chat_widgets')) {
            Schema::create($prefix . 'chat_widgets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->string('public_key')->unique();
                $table->string('name');
                $table->text('allowed_origins')->nullable();
                $table->string('primary_color')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'chat_conversations')) {
            Schema::create($prefix . 'chat_conversations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->string('chat_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->unsignedBigInteger('chat_widget_id')->nullable();
                $table->unsignedBigInteger('chat_visitor_id')->nullable();
                $table->unsignedBigInteger('lead_id')->nullable();
                $table->string('subject')->nullable();
                $table->string('status')->nullable();
                $table->unsignedBigInteger('user_assigned_id')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_deleted_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'chat_visitors')) {
            Schema::create($prefix . 'chat_visitors', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->unsignedBigInteger('chat_widget_id')->nullable();
                $table->string('visitor_token', 64)->nullable()->unique();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->string('current_url', 1024)->nullable();
                $table->string('country_code', 8)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->unsignedBigInteger('person_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'chat_visitor_page_views')) {
            Schema::create($prefix . 'chat_visitor_page_views', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('team_id')->index()->nullable();
                $table->unsignedBigInteger('chat_visitor_id')->index();
                $table->string('url', 2048);
                $table->string('title', 512)->nullable();
                $table->timestamp('viewed_at')->useCurrent();
                $table->index(['chat_visitor_id', 'viewed_at']);
            });
        }

        if (! Schema::hasTable($prefix . 'chat_messages')) {
            Schema::create($prefix . 'chat_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->unsignedBigInteger('chat_conversation_id');
                $table->string('sender_type')->nullable();
                $table->unsignedBigInteger('sender_id')->nullable();
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamp('visitor_read_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable($prefix . 'email_campaign_recipients') && ! Schema::hasColumn($prefix . 'email_campaign_recipients', 'tracking_token')) {
            Schema::table($prefix . 'email_campaign_recipients', function (Blueprint $table) {
                $table->string('tracking_token', 64)->nullable()->index();
            });
        }

        if (Schema::hasTable($prefix . 'sms_campaign_recipients') && ! Schema::hasColumn($prefix . 'sms_campaign_recipients', 'tracking_token')) {
            Schema::table($prefix . 'sms_campaign_recipients', function (Blueprint $table) {
                $table->string('tracking_token', 64)->nullable()->index();
            });
        }

        if (Schema::hasTable($prefix . 'email_campaign_recipients') && ! Schema::hasColumn($prefix . 'email_campaign_recipients', 'error')) {
            Schema::table($prefix . 'email_campaign_recipients', function (Blueprint $table) {
                $table->text('error')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'sms_campaign_recipients') && ! Schema::hasColumn($prefix . 'sms_campaign_recipients', 'error')) {
            Schema::table($prefix . 'sms_campaign_recipients', function (Blueprint $table) {
                $table->text('error')->nullable();
            });
        }

        if (! Schema::hasTable($prefix . 'deal_products')) {
            Schema::create($prefix . 'deal_products', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('deal_id');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('product_variation_id')->nullable();
                $table->bigInteger('price')->nullable();
                $table->decimal('quantity', 15, 3)->nullable();
                $table->decimal('tax_rate')->nullable();
                $table->bigInteger('tax_amount')->nullable();
                $table->bigInteger('amount')->nullable();
                $table->string('currency', 3)->default('USD');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable($prefix . 'address_types') && ! Schema::hasColumn($prefix . 'address_types', 'deleted_at')) {
            Schema::table($prefix . 'address_types', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable($prefix . 'organizations') && ! Schema::hasColumn($prefix . 'organizations', 'total_money_raised')) {
            Schema::table($prefix . 'organizations', function (Blueprint $table) {
                $table->bigInteger('total_money_raised')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'organizations') && ! Schema::hasColumn($prefix . 'organizations', 'number_of_employees')) {
            Schema::table($prefix . 'organizations', function (Blueprint $table) {
                $table->unsignedInteger('number_of_employees')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'organizations') && ! Schema::hasColumn($prefix . 'organizations', 'organization_type_id')) {
            Schema::table($prefix . 'organizations', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_type_id')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'organizations') && ! Schema::hasColumn($prefix . 'organizations', 'timezone_id')) {
            Schema::table($prefix . 'organizations', function (Blueprint $table) {
                $table->unsignedBigInteger('timezone_id')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'organizations') && ! Schema::hasColumn($prefix . 'organizations', 'linkedin')) {
            Schema::table($prefix . 'organizations', function (Blueprint $table) {
                $table->string('linkedin')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'addresses') && ! Schema::hasColumn($prefix . 'addresses', 'suburb')) {
            Schema::table($prefix . 'addresses', function (Blueprint $table) {
                $table->string('suburb')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'deliveries') && ! Schema::hasColumn($prefix . 'deliveries', 'delivery_expected')) {
            Schema::table($prefix . 'deliveries', function (Blueprint $table) {
                $table->date('delivery_expected')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'deliveries') && ! Schema::hasColumn($prefix . 'deliveries', 'delivered_on')) {
            Schema::table($prefix . 'deliveries', function (Blueprint $table) {
                $table->date('delivered_on')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'deliveries') && ! Schema::hasColumn($prefix . 'deliveries', 'user_owner_id')) {
            Schema::table($prefix . 'deliveries', function (Blueprint $table) {
                $table->unsignedBigInteger('user_owner_id')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'deliveries') && ! Schema::hasColumn($prefix . 'deliveries', 'reference')) {
            Schema::table($prefix . 'deliveries', function (Blueprint $table) {
                $table->string('reference')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'purchase_orders') && ! Schema::hasColumn($prefix . 'purchase_orders', 'subtotal')) {
            Schema::table($prefix . 'purchase_orders', function (Blueprint $table) {
                $table->bigInteger('subtotal')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'purchase_orders') && ! Schema::hasColumn($prefix . 'purchase_orders', 'delivery_instructions')) {
            Schema::table($prefix . 'purchase_orders', function (Blueprint $table) {
                $table->text('delivery_instructions')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'purchase_orders') && ! Schema::hasColumn($prefix . 'purchase_orders', 'adjustments')) {
            Schema::table($prefix . 'purchase_orders', function (Blueprint $table) {
                $table->bigInteger('adjustments')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'purchase_orders') && ! Schema::hasColumn($prefix . 'purchase_orders', 'sent')) {
            Schema::table($prefix . 'purchase_orders', function (Blueprint $table) {
                $table->boolean('sent')->default(false);
            });
        }

        if (Schema::hasTable($prefix . 'purchase_orders') && ! Schema::hasColumn($prefix . 'purchase_orders', 'delivery_date')) {
            Schema::table($prefix . 'purchase_orders', function (Blueprint $table) {
                $table->date('delivery_date')->nullable();
            });
        }

        if (! Schema::hasTable($prefix . 'email_campaign_clicks')) {
            Schema::create($prefix . 'email_campaign_clicks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('email_campaign_recipient_id')->index();
                $table->text('original_url');
                $table->timestamp('clicked_at')->nullable();
                $table->string('ip', 64)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($prefix . 'sms_campaign_clicks')) {
            Schema::create($prefix . 'sms_campaign_clicks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sms_campaign_recipient_id')->index();
                $table->text('original_url');
                $table->timestamp('clicked_at')->nullable();
                $table->string('ip', 64)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('audits')) {
            Schema::create('audits', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('user_type')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('event');
                $table->morphs('auditable');
                $table->text('old_values')->nullable();
                $table->text('new_values')->nullable();
                $table->text('url')->nullable();
                $table->ipAddress('ip_address')->nullable();
                $table->string('user_agent', 1023)->nullable();
                $table->string('tags')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'user_type']);
            });
        }

        if (! Schema::hasTable($prefix . 'xero_contacts')) {
            Schema::create($prefix . 'xero_contacts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->index()->nullable();
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->string('contact_id')->nullable();
                $table->string('name')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'xero_items')) {
            Schema::create($prefix . 'xero_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->index()->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('item_id')->nullable();
                $table->string('code')->nullable();
                $table->string('name')->nullable();
                $table->boolean('inventory_tracked')->default(false);
                $table->boolean('is_purchased')->default(false);
                $table->integer('purchase_price')->nullable();
                $table->string('purchase_description')->nullable();
                $table->boolean('is_sold')->default(false);
                $table->integer('sell_price')->nullable();
                $table->string('sell_description')->nullable();
                $table->integer('quantity_on_hand')->nullable();
                $table->dateTime('updated_date')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'xero_invoices')) {
            Schema::create($prefix . 'xero_invoices', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->index()->nullable();
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->string('xero_type')->nullable();
                $table->string('xero_id')->nullable();
                $table->string('number')->nullable();
                $table->string('reference')->nullable();
                $table->integer('subtotal')->nullable();
                $table->integer('total_tax')->nullable();
                $table->integer('total')->nullable();
                $table->string('status')->nullable();
                $table->integer('amount_due')->nullable();
                $table->integer('amount_paid')->nullable();
                $table->integer('amount_credited')->nullable();
                $table->date('issue_date')->nullable();
                $table->date('due_date')->nullable();
                $table->string('line_amount_types')->nullable();
                $table->string('currency_code', 3)->nullable();
                $table->dateTime('fully_paid_at')->nullable();
                $table->dateTime('xero_updated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'xero_purchase_orders')) {
            Schema::create($prefix . 'xero_purchase_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->index()->nullable();
                $table->unsignedBigInteger('purchase_order_id')->nullable();
                $table->string('xero_type')->nullable();
                $table->string('xero_id')->nullable();
                $table->string('number')->nullable();
                $table->string('reference')->nullable();
                $table->integer('subtotal')->nullable();
                $table->integer('total_tax')->nullable();
                $table->integer('total')->nullable();
                $table->string('status')->nullable();
                $table->date('issue_date')->nullable();
                $table->date('delivery_date')->nullable();
                $table->string('line_amount_types')->nullable();
                $table->string('currency_code', 3)->nullable();
                $table->dateTime('xero_updated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'two_factor_secret')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'crm_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('crm_access')->default(true);
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'last_online_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_online_at')->nullable();
            });
        }

        if (! Schema::hasTable($prefix . 'invoice_payments')) {
            Schema::create($prefix . 'invoice_payments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->integer('amount')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable($prefix . 'tasks') && ! Schema::hasColumn($prefix . 'tasks', 'user_owner_id')) {
            Schema::table($prefix . 'tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('user_owner_id')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'meetings') && ! Schema::hasColumn($prefix . 'meetings', 'user_owner_id')) {
            Schema::table($prefix . 'meetings', function (Blueprint $table) {
                $table->unsignedBigInteger('user_owner_id')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'meetings') && ! Schema::hasColumn($prefix . 'meetings', 'user_deleted_id')) {
            Schema::table($prefix . 'meetings', function (Blueprint $table) {
                $table->unsignedBigInteger('user_deleted_id')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'lunches') && ! Schema::hasColumn($prefix . 'lunches', 'user_owner_id')) {
            Schema::table($prefix . 'lunches', function (Blueprint $table) {
                $table->unsignedBigInteger('user_owner_id')->nullable();
            });
        }

        if (Schema::hasTable($prefix . 'lunches') && ! Schema::hasColumn($prefix . 'lunches', 'user_deleted_id')) {
            Schema::table($prefix . 'lunches', function (Blueprint $table) {
                $table->unsignedBigInteger('user_deleted_id')->nullable();
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (Schema::hasTable($prefix . 'calls') && ! Schema::hasColumn($prefix . 'calls', 'location')) {
            Schema::table($prefix . 'calls', function (Blueprint $table) {
                $table->string('location')->nullable();
            });
        }

        if (! Schema::hasTable($prefix . 'contacts')) {
            Schema::create($prefix . 'contacts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id')->nullable();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->morphs('contactable');
                $table->morphs('entityable');
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_deleted_id')->nullable();
                $table->unsignedBigInteger('user_restored_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'features')) {
            Schema::create($prefix . 'features', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id');
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->unsignedInteger('number')->nullable();
                $table->string('feature_id')->nullable();
                $table->string('title');
                $table->text('description')->nullable();
                $table->boolean('is_public')->default(true);
                $table->unsignedInteger('votes_count')->default(0);
                $table->unsignedInteger('comments_count')->default(0);
                $table->unsignedInteger('views_count')->default(0);
                $table->unsignedBigInteger('feature_status_id')->nullable()->index();
                $table->unsignedBigInteger('submitted_by_user_id')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_deleted_id')->nullable();
                $table->unsignedBigInteger('user_restored_id')->nullable();
                $table->unsignedBigInteger('user_owner_id')->nullable();
                $table->unsignedBigInteger('user_assigned_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'feature_statuses')) {
            Schema::create($prefix . 'feature_statuses', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id');
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('color')->nullable();
                $table->tinyInteger('order')->default(0);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_closed')->default(false);
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_deleted_id')->nullable();
                $table->unsignedBigInteger('user_restored_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'feature_votes')) {
            Schema::create($prefix . 'feature_votes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->unsignedBigInteger('feature_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->timestamps();

                $table->unique(['feature_id', 'user_id'], 'crm_feature_votes_feature_user_unique');
            });
        }

        if (! Schema::hasTable($prefix . 'feature_comments')) {
            Schema::create($prefix . 'feature_comments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id');
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->unsignedBigInteger('feature_id')->index();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->text('body');
                $table->boolean('is_admin_reply')->default(false);
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_deleted_id')->nullable();
                $table->unsignedBigInteger('user_restored_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable($prefix . 'feature_views')) {
            Schema::create($prefix . 'feature_views', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('feature_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('ip_hash', 64)->nullable()->index();
                $table->timestamp('viewed_at')->index();
            });
        }

        if (! Schema::hasTable($prefix . 'monitors')) {
            Schema::create($prefix . 'monitors', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id');
                $table->string('monitor_id')->nullable();
                $table->unsignedInteger('number')->nullable();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->string('type')->default('https');
                $table->string('url', 1024);
                $table->string('host')->nullable();
                $table->string('method', 16)->default('GET');
                $table->json('headers')->nullable();
                $table->text('body')->nullable();
                $table->unsignedInteger('expected_status_code')->default(200);
                $table->unsignedInteger('interval')->default(5);
                $table->unsignedInteger('timeout')->default(30);
                $table->boolean('is_active')->default(true);
                $table->boolean('uptime_enabled')->default(true);
                $table->boolean('ssl_enabled')->default(false);
                $table->unsignedInteger('perf_threshold_ms')->nullable();
                $table->unsignedInteger('downtime_minutes_before_alert')->nullable();
                $table->string('last_status')->nullable();
                $table->unsignedInteger('last_response_time')->nullable();
                $table->unsignedInteger('last_status_code')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('last_status_changed_at')->nullable();
                $table->timestamp('down_since_at')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->timestamp('ssl_last_checked_at')->nullable();
                $table->string('ssl_status')->nullable();
                $table->string('ssl_issuer')->nullable();
                $table->timestamp('ssl_expires_at')->nullable();
                $table->timestamp('ssl_notified_at')->nullable();
                $table->unsignedBigInteger('user_created_id')->nullable();
                $table->unsignedBigInteger('user_updated_id')->nullable();
                $table->unsignedBigInteger('user_deleted_id')->nullable();
                $table->unsignedBigInteger('user_restored_id')->nullable();
                $table->unsignedBigInteger('user_owner_id')->nullable();
                $table->unsignedBigInteger('user_assigned_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['team_id', 'last_checked_at']);
            });
        }

        // The host users table may come from testbench's own migrations rather
        // than TestSchema, so add the CRM columns the importers write here.
        foreach (['crm_access', 'mailing_list'] as $userColumn) {
            if (Schema::hasTable('users') && ! Schema::hasColumn('users', $userColumn)) {
                Schema::table('users', function (Blueprint $table) use ($userColumn) {
                    $table->boolean($userColumn)->default(false);
                });
            }
        }

        // core 2.4.0: the invitation lifecycle. Mirrors
        // database/updates/2026_07_26_111131_create_crm_user_invitations_table
        // plus the soft-deletes/last_sent_at follow-up. The ->foreign() calls
        // are dropped deliberately — the harness runs with
        // foreign_key_constraints => false.
        if (! Schema::hasTable($prefix . 'user_invitations')) {
            Schema::create($prefix . 'user_invitations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id');
                $table->string('code', 64)->unique();
                $table->unsignedBigInteger('team_id')->index()->nullable();
                $table->string('email');
                $table->unsignedBigInteger('role_id')->nullable();
                $table->unsignedBigInteger('invited_by')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // core 2.4.0: the per-record PDF template picker.
        foreach (['quotes', 'orders', 'purchase_orders', 'deliveries', 'invoices'] as $documentTable) {
            if (Schema::hasTable($prefix . $documentTable) && ! Schema::hasColumn($prefix . $documentTable, 'pdf_template')) {
                Schema::table($prefix . $documentTable, function (Blueprint $table) {
                    $table->string('pdf_template')->nullable();
                });
            }
        }

        // core 2.4.0: performance / recovery alert rate limiting.
        foreach (['perf_notified_at', 'recovered_notified_at'] as $monitorColumn) {
            if (Schema::hasTable($prefix . 'monitors') && ! Schema::hasColumn($prefix . 'monitors', $monitorColumn)) {
                Schema::table($prefix . 'monitors', function (Blueprint $table) use ($monitorColumn) {
                    $table->timestamp($monitorColumn)->nullable();
                });
            }
        }

        if (! Schema::hasTable($prefix . 'monitor_checks')) {
            Schema::create($prefix . 'monitor_checks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('external_id');
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->unsignedBigInteger('monitor_id');
                $table->string('type')->default('http');
                $table->string('status');
                $table->unsignedInteger('response_time')->nullable();
                $table->unsignedInteger('status_code')->nullable();
                $table->text('error_message')->nullable();
                $table->longText('response_body')->nullable();
                $table->timestamp('ssl_expires_at')->nullable();
                $table->timestamp('checked_at');
                $table->timestamps();

                $table->index(['monitor_id', 'type', 'checked_at']);
            });
        }
    }

    public function down(): void
    {
        // No-op: the test database is dropped between test runs.
    }
};
