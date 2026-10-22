/* Copyright (C) 2026 Zach Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Module VariableBom:
 * - MO creation from a variable BOM: set the product to produce from the selected attribute values,
 *   and show the lines of the BOM resolved for this variant.
 * - Tab Variants of a BOM: show the quantities of the selected attribute.
 */

jQuery(function ($) {
	var $selector = $('.variablebom_selector');

	/**
	 * Set the product to produce of the MO form, for a select or for an autocomplete input
	 *
	 * @param {number} id		Id of product
	 * @param {string} label	Label of product
	 * @return {void}
	 */
	function setProductToProduce(id, label) {
		var $product = $('#fk_product');
		if ($product.is('select')) {
			if (!$product.find('option[value="' + id + '"]').length) {
				$product.append(new Option(label, id));
			}
			$product.val(String(id)).trigger('change');
		} else {
			$product.val(String(id)).trigger('change');
			$('#search_fk_product').val(label);
		}
	}

	/**
	 * Resolve the variant to produce from the selected attribute values
	 *
	 * @return {void}
	 */
	function resolveVariant() {
		var params = {fk_bom: $selector.data('bom')};
		$selector.find('select.variablebom_attr').each(function () {
			if ($(this).val() > 0) {
				params['attr[' + $(this).data('attr') + ']'] = $(this).val();
			}
		});
		var $out = $selector.closest('td').find('.variablebom_resolved');

		$.getJSON($selector.data('url'), params, function (data) {
			$out.empty().removeClass('opacitymedium');
			if (data.fk_product > 0) {
				setProductToProduce(data.fk_product, data.label);
				$out.append($('<strong>').text(data.label));
				var $list = $('<ul class="small">');
				$.each(data.lines, function (i, line) {
					$list.append($('<li>').text(line.qty + ' x ' + line.label));
				});
				$out.append($list);
			}
			$.each(data.errors, function (i, error) {
				$out.append($('<div class="warning">').text(error));
			});
			if (!data.fk_product && !data.errors.length) {
				$out.addClass('opacitymedium').text($selector.data('help'));
			}
		});
	}

	if ($selector.length) {
		$selector.on('change', 'select.variablebom_attr', resolveVariant);
		if ($selector.find('select.variablebom_attr').filter(function () { return $(this).val() > 0; }).length) {
			resolveVariant();
		}
	}

	$('select.variablebom_qtyattr').on('change', function () {
		$('.variablebom_qty').hide();
		$('.variablebom_qty_' + $(this).val()).show();
		$('.variablebom_qty_help').toggle($(this).val() > 0);
	});
});
