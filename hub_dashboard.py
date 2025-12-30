import streamlit as st
import pandas as pd
import sqlite3
import hashlib

USERS = {
    'admin': hashlib.sha256('Pra2026!'.encode()).hexdigest(),
    'manager': hashlib.sha256('Site2026!'.encode()).hexdigest(),
    'viewer': hashlib.sha256('View2026!'.encode()).hexdigest()
}

st.set_page_config(page_title="Estate Hub Malta", page_icon="./logo_icon.png", layout="wide")

# PERSISTENT LOGIN WITH COOKIES
if 'user_token' not in st.session_state:
    st.session_state.user_token = st.secrets.get('user_token', None)

class Database:
    def __init__(self):
        self.conn = sqlite3.connect('estate_hub.db', check_same_thread=False)
        self.init_db()
    
    def init_db(self):
        # Clients table
        self.conn.execute("CREATE TABLE IF NOT EXISTS clients (id INTEGER PRIMARY KEY, name TEXT UNIQUE)")
        # Projects table
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS projects (
                id TEXT PRIMARY KEY, name TEXT, client TEXT, city TEXT,
                pa_number TEXT DEFAULT '', bca_status TEXT DEFAULT 'AWAITING', 
                status TEXT DEFAULT 'Pending', notes TEXT
            )
        """)
        
        # Add sample clients
        sample_clients = ['Agius', 'Blue Clay', 'Excel Investments', 'Next Developers']
        self.conn.executemany("INSERT OR IGNORE INTO clients (name) VALUES (?)", [(c,) for c in sample_clients])
        
        # Add sample projects[file:142]
        if pd.read_sql("SELECT COUNT(*) FROM projects", self.conn).iloc[0,0] == 0:
            sample_projects = [
                ('P001', 'Dirjanu Supermarket', 'Agius', 'Ghajnsielem', '7246/22', 'DONE', 'Mobilised', ''),
                ('P002', 'Hotel All Season', 'Blue Clay', 'Victoria', '7298/24', 'AWAITING', 'In Process', ''),
                ('P003', 'Cutajar Houses', 'Excel Investments', 'Xaghra', '4893/23', 'NO', 'Pending', ''),
                ('P004', 'Ex BOV Nadur', 'Excel Investments', 'Nadur', '575/24', 'AWAITING', 'In Process', '')
            ]
            self.conn.executemany("INSERT INTO projects VALUES(?,?,?,?,?,?,?,?)", sample_projects)
            self.conn.commit()
    
    def get_clients(self):
        return pd.read_sql("SELECT name FROM clients ORDER BY name", self.conn)['name'].tolist()
    
    def add_client(self, name):
        self.conn.execute("INSERT OR IGNORE INTO clients (name) VALUES (?)", (name,))
        self.conn.commit()
    
    def get_projects(self):
        return pd.read_sql("SELECT * FROM projects ORDER BY name", self.conn)
    
    def add_project(self, name, client, city, pa_number):
        project_id = f"P{len(self.get_projects())+1:03d}"
        self.conn.execute("""
            INSERT INTO projects (id, name, client, city, pa_number, bca_status, status)
            VALUES (?, ?, ?, ?, ?, 'AWAITING', 'Pending')
        """, (project_id, name, client, city, pa_number))
        self.conn.commit()
        return project_id
    
    def update_project(self, project_id, pa_number, bca_status, status, notes):
        self.conn.execute("""
            UPDATE projects SET pa_number=?, bca_status=?, status=?, notes=? 
            WHERE id=?
        """, (pa_number, bca_status, status, notes, project_id))
        self.conn.commit()

db = Database()

def login_page():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <img src="logo.png" width="250">
        <h1 style='color: #1f77b4;'>Estate Hub Malta</h1>
    </div>
    """, unsafe_allow_html=True)
    
    col1, col2 = st.columns(2)
    with col1:
        username = st.text_input("👤 Username")
    with col2:
        password = st.text_input("🔑 Password", type="password")
    
    if st.button("🚀 Login", type="primary", use_container_width=True):
        password_hash = hashlib.sha256(password.encode()).hexdigest()
        if username in USERS and USERS[username] == password_hash:
            st.session_state.logged_in = True
            st.session_state.username = username
            st.success(f"✅ Welcome {username.title()}!")
            st.rerun()
        else:
            st.error("❌ Invalid credentials")
    
    st.caption("admin/Pra2026! | manager/Site2026! | viewer/View2026!")

def mobilization_module():
    st.header("🚧 Mobilization Tracker[file:142]")
    
    # Stats
    projects_df = db.get_projects()
    col1, col2, col3 = st.columns(3)
    col1.metric("Total", len(projects_df))
    col2.metric("Mobilised", len(projects_df[projects_df['status']=='Mobilised']))
    col3.metric("Pending", len(projects_df[projects_df['status']=='Pending']))
    
    # Progress chart
    chart_data = []
    for _, row in projects_df.iterrows():
        progress = 0
        if row['pa_number']: progress += 40
        if row['bca_status'] != 'AWAITING': progress += 30
        if row['status'] in ['Ready', 'Mobilised']: progress += 30
        chart_data.append({"Project": row['name'][:12], "Progress": progress})
    st.subheader("📈 Progress")
    st.bar_chart(pd.DataFrame(chart_data).set_index("Project"))
    
    col1, col2 = st.columns(2)
    
    with col1:
        st.subheader("📋 Projects")
        st.dataframe(projects_df[['name', 'client', 'city', 'pa_number', 'bca_status', 'status']], 
                    use_container_width=True)
    
    with col2:
        st.subheader("➕ Add New")
        
        # Add Client
        new_client = st.text_input("New Client")
        if st.button("Add Client", key="add_client"):
            if new_client:
                db.add_client(new_client)
                st.success(f"✅ {new_client} added!")
                st.rerun()
        
        st.divider()
        
        # Add Project
        st.subheader("New Project")
        project_name = st.text_input("Project Name")
        client_name = st.selectbox("Client", db.get_clients())
        city = st.text_input("City")
        pa_num = st.text_input("PA Number")
        
        if st.button("Create Project", key="add_project"):
            if project_name and city:
                db.add_project(project_name, client_name, city, pa_num)
                st.success("✅ Project created!")
                st.rerun()
    
    st.divider()
    st.subheader("🔄 Update Project")
    
    project_options = {row['id']: f"{row['name']} ({row['city']})" for _, row in projects_df.iterrows()}
    selected_id = st.selectbox("Select", list(project_options.keys()), format_func=lambda x: project_options[x])
    
    selected = projects_df[projects_df['id']==selected_id].iloc[0]
    
    col1, col2, col3, col4 = st.columns(4)
    with col1: new_pa = st.text_input("PA", selected['pa_number'], key=f"pa_{selected_id}")
    with col2: new_bca = st.selectbox("BCA", ['AWAITING', 'DONE', 'NO'], index=['AWAITING', 'DONE', 'NO'].index(selected['bca_status']), key=f"bca_{selected_id}")
    with col3: new_status = st.selectbox("Status", ['Pending', 'In Process', 'Ready', 'Mobilised'], key=f"status_{selected_id}")
    with col4: notes = st.text_input("Notes", selected.get('notes', ''), key=f"notes_{selected_id}")
    
    if st.button("💾 Update", type="primary"):
        db.update_project(selected_id, new_pa, new_bca, new_status, notes)
        st.success("✅ Updated!")
        st.rerun()

def dashboard():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <img src="./logo.png" width="200">
        <h1 style='color: #1f77b4;'>Estate Hub Malta</h1>
    </div>
    """, unsafe_allow_html=True)
    
    tab1, tab2 = st.tabs(["📊 Overview", "🚧 Mobilization"])
    
    with tab1:
        projects_df = db.get_projects()
        col1, col2, col3 = st.columns(3)
        col1.metric("Projects", len(projects_df))
        col2.metric("Clients", len(db.get_clients()))
        col3.metric("Gozo Sites", len(projects_df[projects_df['city'].str.contains('Gozo', na=False)]))
        
        if st.button("🚪 Logout"):
            st.session_state.clear()
            st.rerun()
    
    with tab2:
        mobilization_module()

def main():
    if st.session_state.get('logged_in', False):
        dashboard()
    else:
        login_page()

if __name__ == "__main__":
    main()
