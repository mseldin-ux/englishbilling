<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('program')->nullable();
            $table->longText('programdesc')->nullable();
            $table->integer('servicedays')->nullable();
        });

        Schema::create('globals', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('lastinvoiceid')->nullable();
            $table->integer('version')->nullable();
            foreach (['statement', 'invoice'] as $prefix) {
                foreach (['from_email', 'to_ccs', 'to_bccs', 'subject', 'emailbody'] as $field) {
                    $table->string("{$prefix}_{$field}")->nullable();
                }
            }
        });

        Schema::create('students', function (Blueprint $table) {
            $table->increments('id');
            foreach (['cohort', 'last_name', 'middle_name', 'first_name', 'streetaddress',
                'city', 'state', 'country', 'school', 'emailaddr', 'pmt_type',
                'delivery_method', 'invoicenumber', 'group_name', 'sponsor_contact',
                'sponsor_full_address'] as $field) {
                $table->string($field)->nullable();
            }
            foreach (['zipcode' => 15, 'sponsor_email' => 128, 'sponsor_phone' => 64,
                'phone' => 32, 'stid' => 32, 'stcode' => 8, 'gender' => 16] as $field => $length) {
                $table->string($field, $length)->nullable();
            }
            foreach (['studentcomments', 'invoice_notes', 'sponsorship_note', 'student_notes'] as $field) {
                $table->longText($field)->nullable();
            }
            foreach (['payment_amount_due', 'balance'] as $field) {
                $table->decimal($field, 15, 2)->nullable();
            }
            foreach (['graddate', 'actualdategrad', 'next_due_date', 'inactive_date',
                'loa_date', 'pifdate', 'wd_date', 'sus_date', 'start_date',
                'extension_start_date'] as $field) {
                $table->dateTime($field, 6)->nullable();
            }
            foreach (['inactive', 'loa', 'pif', 'wd', 'sus', 'in_group', 'graduated',
                'onextension', 'sponsored', 'archived', 'transferred'] as $field) {
                $table->boolean($field)->nullable();
            }
            $table->unsignedSmallInteger('flag')->nullable();
            $table->unsignedInteger('program_id')->nullable();
            $table->foreign('program_id')->references('id')->on('programs')->restrictOnDelete();
            foreach (['last_name', 'middle_name', 'first_name', 'zipcode', 'gender',
                'phone', 'stcode', 'stid'] as $field) {
                $table->index($field);
            }
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('datesent', 6)->nullable();
            $table->dateTime('nextdate', 6)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            foreach (['notes', 'invoicereference', 'user_sent', 'urgent_message'] as $field) {
                $table->string($field)->nullable();
            }
            $table->string('batchid', 128)->nullable()->index();
            $table->unsignedInteger('student_id')->nullable();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->binary('invoice_file')->nullable();
        });
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement('ALTER TABLE invoices MODIFY invoice_file LONGBLOB NULL');
        }

        Schema::create('studenttransactions', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('transactiondate', 6)->nullable();
            $table->dateTime('processeddate', 6)->nullable();
            $table->string('transactiontype')->nullable();
            $table->decimal('transactionamount', 15, 2)->nullable();
            $table->longText('transactionnotes')->nullable();
            $table->unsignedInteger('student_id')->nullable();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->unsignedInteger('invoice_id')->nullable();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });

        Schema::create('loas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('student_id')->nullable();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->dateTime('loa_start', 6)->nullable();
            $table->dateTime('loa_end', 6)->nullable();
        });

        Schema::create('paymentplans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('paymentplan')->nullable();
            $table->longText('paymentplandesc')->nullable();
            $table->string('monthslist')->nullable();
        });

        Schema::create('studentpaymentplans', function (Blueprint $table) {
            $table->increments('id');
            $table->longText('paymentplandesc')->nullable();
            $table->unsignedInteger('student_id')->nullable();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
        });

        Schema::create('anticipatedpayment', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('studentpaymentplanid_id')->nullable();
            $table->foreign('studentpaymentplanid_id')->references('id')->on('studentpaymentplans')->restrictOnDelete();
            $table->unsignedInteger('student_id')->nullable();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->dateTime('paymentduedate', 6)->nullable();
            $table->dateTime('paymentinvoicedate', 6)->nullable();
            $table->decimal('paymentamount', 15, 2)->nullable();
            $table->double('paymentpercent')->nullable();
            $table->string('paymentnotes')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['anticipatedpayment', 'studentpaymentplans', 'paymentplans', 'loas',
            'studenttransactions', 'invoices', 'students', 'globals', 'programs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
