import { openDB } from 'idb';

export type LocalReceipt = {
  id: string;
  merchant?: string;
  total?: number;
  currency?: string;
  purchased_at?: string;
  notes?: string;
  raw_text?: string;
  category?: string;
  tags?: string[];
  offline?: boolean;
  synced_at?: string;
};

export type QueueItem = {
  id?: number;
  type: 'create_receipt';
  payload: Record<string, unknown>;
  createdAt: string;
};

const DB_NAME = 'onledge-offline';
const DB_VERSION = 1;

async function getDb() {
  return openDB(DB_NAME, DB_VERSION, {
    upgrade(db) {
      if (!db.objectStoreNames.contains('receipts')) {
        db.createObjectStore('receipts', { keyPath: 'id' });
      }

      if (!db.objectStoreNames.contains('uploadQueue')) {
        db.createObjectStore('uploadQueue', { keyPath: 'id', autoIncrement: true });
      }
    }
  });
}

export async function putLocalReceipt(receipt: LocalReceipt): Promise<void> {
  const db = await getDb();
  await db.put('receipts', receipt);
}

export async function getLocalReceipts(): Promise<LocalReceipt[]> {
  const db = await getDb();
  return db.getAll('receipts');
}

export async function clearLocalReceipts(): Promise<void> {
  const db = await getDb();
  await db.clear('receipts');
}

export async function enqueue(item: QueueItem): Promise<void> {
  const db = await getDb();
  await db.add('uploadQueue', item);
}

export async function listQueue(): Promise<QueueItem[]> {
  const db = await getDb();
  return db.getAll('uploadQueue');
}

export async function removeQueueItem(id: number): Promise<void> {
  const db = await getDb();
  await db.delete('uploadQueue', id);
}
