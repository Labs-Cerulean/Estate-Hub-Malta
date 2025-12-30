import sqlite3
import pandas as pd
from contextlib import contextmanager

class Database:
    def __init__(self, db_path="estate_hub.db"):
        self.db_path = db_path
        self.init_db()
    
    @contextmanager
    def get_connection(self):
        conn = sqlite3.connect(self.db_path)
        try:
            yield conn
        finally:
            conn.close()
    
    def init_db(self):
        with self.get_connection() as conn:
            # Clients table
            conn.execute("""
                CREATE TABLE IF NOT EXISTS clients (
                    id INTEGER PRIMARY KEY,
                    name TEXT UNIQUE NOT NULL
                )
            """)
            
            # Locations table  
            conn.execute("""
                CREATE TABLE IF NOT EXISTS locations (
                    id INTEGER PRIMARY KEY,
                    city TEXT,
                    region TEXT
                )
            """)
            
            # Projects table
            conn.execute("""
                CREATE TABLE IF NOT EXISTS projects (
                    id TEXT PRIMARY KEY,
                    name TEXT NOT NULL,
                    client_id INTEGER,
                    location_id INTEGER,
                    pa_number TEXT,
                    bca_status TEXT DEFAULT 'AWAITING',
                    status TEXT DEFAULT 'Pending',
                    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(client_id) REFERENCES clients(id),
                    FOREIGN KEY(location_id) REFERENCES locations(id)
                )
            """)
            
            # Populate from your Excel[file:142]
            self.populate_from_excel()
            conn.commit()
    
    def populate_from_excel(self):
        """Load your New-Sites-to-Open.xlsx[file:142]"""
        try:
            df = pd.read_excel('New-Sites-to-Open.xlsx', skiprows=1, nrows=20)
            df.columns = ['client', 'name', 'city', 'location', 'pa_number', 'akkwist', 'pa_approved', 
                         'archeologist', 'change_applicant', 'geological_test', 'condition_reports', 
                         'method_statements', 'insurance', 'bankina_bg', 'wb_bg', 'umbrella_bg', 
                         'ohsa', 'responsibility_form', 'bca_clearance']
            
            with self.get_connection() as conn:
                for _, row in df.iterrows():
                    if pd.notna(row['name']) and pd.notna(row['client']):
                        # Add client
                        conn.execute("INSERT OR IGNORE INTO clients (name) VALUES (?)", (row['client'],))
                        client_id = conn.execute("SELECT id FROM clients WHERE name = ?", (row['client'],)).fetchone()[0]
                        
                        # Add location
                        city, region = row['city'], row['location'] or 'Gozo'
                        conn.execute("INSERT OR IGNORE INTO locations (city, region) VALUES (?, ?)", (city, region))
                        location_id = conn.execute("SELECT id FROM locations WHERE city = ? AND region = ?", (city, region)).fetchone()[0]
                        
                        # Add project
                        project_id = f"P{len(conn.execute('SELECT * FROM projects').fetchall()):03d}"
                        conn.execute("""
                            INSERT OR REPLACE INTO projects (id, name, client_id, location_id, pa_number, bca_status, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        """, (project_id, row['name'], client_id, location_id, row['pa_number'], 
                              row.get('bca_clearance', 'AWAITING'), 'Pending'))
        except:
            # Fallback sample data
            pass
    
    def get_projects(self):
        with self.get_connection() as conn:
            return pd.read_sql("""
                SELECT p.*, c.name as client_name, l.city, l.region 
                FROM projects p 
                JOIN clients c ON p.client_id = c.id 
                JOIN locations l ON p.location_id = l.id
            """, conn)
