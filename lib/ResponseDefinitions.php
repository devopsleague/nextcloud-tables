<?php

namespace OCA\Tables;


/**
 * @psalm-type TablesTable = array{
 * 	id: int,
 * 	title: string,
 * 	emoji: string,
 * 	ownership: string,
 * 	ownerDisplayName: string,
 * 	createdBy: string,
 * 	createdAt: string,
 * 	lastEditBy: string,
 * 	lastEditAt: string,
 * 	isShared: bool,
 * 	onSharePermissions: ?array{
 * 		read: bool,
 * 		create: bool,
 *     	update: bool,
 * 		delete: bool,
 * 		manage: bool,
 * 	},
 * hasShares: bool,
 * rowsCount: int
 * }
 */
class ResponseDefinitions {
}
