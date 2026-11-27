<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications: the templates (platform defaults and organisations' own),
 * each organisation's channel settings, and the messages sent. Only creates
 * tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 26)->unique();
                // Empty: the platform's default; else the organisation's own.
                $table->unsignedBigInteger('organisation_id')->nullable();
                // e.g. payment.rejected
                $table->string('type', 100);
                // email (whatsapp, in-app… later)
                $table->string('channel', 16);
                $table->string('subject')->nullable();
                $table->text('body');
                $table->boolean('active')->default(true);
                // An organisation's copy edited by them (else the default is used and kept in step).
                $table->boolean('customised')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['organisation_id', 'type', 'channel']);
            });
        }

        if (! Schema::hasTable('notification_channels')) {
            Schema::create('notification_channels', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 26)->unique();
                // Empty: the platform's.
                $table->unsignedBigInteger('organisation_id')->nullable();
                $table->string('channel', 16);
                // e.g. email: from_name, reply_to (later: SMTP; secrets encrypted).
                $table->text('settings')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamp('tested_at')->nullable();
                $table->string('test_result')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['organisation_id', 'channel']);
            });
        }

        if (! Schema::hasTable('notification_logs')) {
            Schema::create('notification_logs', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 26)->unique();
                $table->unsignedBigInteger('organisation_id')->nullable();
                $table->string('type', 100);
                $table->string('channel', 16);
                // An email address (a phone number for WhatsApp later).
                $table->string('recipient');
                $table->string('recipient_name')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                // What it is about, e.g. an event registration or a payment.
                $table->string('ref_type', 100)->nullable();
                $table->string('ref_id', 64)->nullable();
                // The values the template was filled with (rendered when sent).
                $table->json('variables')->nullable();
                $table->string('subject')->nullable();
                // queued | sent | failed | skipped
                $table->string('status', 16)->default('queued');
                $table->text('error')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['organisation_id', 'status']);
                $table->index(['ref_type', 'ref_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('notification_templates');
    }
};
