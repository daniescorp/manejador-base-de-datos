<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_products', function (Blueprint $table) {
            $table->string('codigo_producto')->nullable()->index();
            $table->string('codigo_original')->nullable();
            $table->string('sku_original')->nullable();
            $table->string('ean_original')->nullable();
            $table->string('ean_validado')->nullable();

            $table->text('nombre_original')->nullable();
            $table->text('nombre_sin_marca')->nullable();
            $table->text('nombre_homologado')->nullable();
            $table->text('descripcion_catalogo')->nullable();
            $table->text('titulo_shopify')->nullable();
            $table->text('descripcion_app')->nullable();
            $table->text('descripcion_interna')->nullable();

            $table->string('marca_original')->nullable();
            $table->string('marca_homologada')->nullable()->index();
            $table->boolean('marca_detectada_en_nombre')->default(false);
            $table->string('marca_inferida')->nullable();
            $table->boolean('requiere_revision_marca')->default(false);
            $table->string('nivel_confianza_marca')->nullable();

            $table->string('categoria_original')->nullable();
            $table->string('categoria_homologada')->nullable()->index();
            $table->string('grupo_original')->nullable();
            $table->string('grupo_homologado')->nullable();
            $table->string('familia_original')->nullable();
            $table->string('familia_homologada')->nullable();

            $table->string('medida_original')->nullable();
            $table->decimal('contenido_valor', 10, 3)->nullable();
            $table->string('unidad_original')->nullable();
            $table->string('unidad_normalizada')->nullable();
            $table->unsignedInteger('cantidad_unidades')->nullable();
            $table->decimal('medida_valor', 10, 3)->nullable();
            $table->string('medida_catalogo')->nullable();
            $table->boolean('medida_requiere_revision')->default(false);

            $table->string('uxb_original')->nullable();
            $table->unsignedInteger('uxb_validado')->nullable();
            $table->boolean('uxb_requiere_revision')->default(false);

            $table->string('estado_homologacion')->nullable()->index();
            $table->boolean('requiere_revision')->default(false)->index();
            $table->text('observaciones')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('master_products', function (Blueprint $table) {
            $table->dropForeign(['approved_by_id']);
            $table->dropIndex(['codigo_producto']);
            $table->dropIndex(['marca_homologada']);
            $table->dropIndex(['categoria_homologada']);
            $table->dropIndex(['estado_homologacion']);
            $table->dropIndex(['requiere_revision']);
            $table->dropColumn([
                'codigo_producto',
                'codigo_original',
                'sku_original',
                'ean_original',
                'ean_validado',
                'nombre_original',
                'nombre_sin_marca',
                'nombre_homologado',
                'descripcion_catalogo',
                'titulo_shopify',
                'descripcion_app',
                'descripcion_interna',
                'marca_original',
                'marca_homologada',
                'marca_detectada_en_nombre',
                'marca_inferida',
                'requiere_revision_marca',
                'nivel_confianza_marca',
                'categoria_original',
                'categoria_homologada',
                'grupo_original',
                'grupo_homologado',
                'familia_original',
                'familia_homologada',
                'medida_original',
                'contenido_valor',
                'unidad_original',
                'unidad_normalizada',
                'cantidad_unidades',
                'medida_valor',
                'medida_catalogo',
                'medida_requiere_revision',
                'uxb_original',
                'uxb_validado',
                'uxb_requiere_revision',
                'estado_homologacion',
                'requiere_revision',
                'observaciones',
                'approved_by_id',
                'approved_at',
            ]);
        });
    }
};
