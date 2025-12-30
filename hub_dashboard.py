import streamlit as st
import pandas as pd
import sqlite3
from datetime import datetime

# Simple users
USERS = {'admin': 'Pra2026!', 'manager': 'Site2026!', 'viewer': 'View2026!'}

st.set_page_config(page_title="Estate Hub Malta", page_icon="logo.png", layout="wide")

class Database:
    def __init__(self):
        self.conn = sqlite3.connect('estate_hub.db', check_same_thread=False)
        self.init_tables()
    
    def init_tables(self):
        # Clients
        self.conn.execute("CREATE TABLE IF NOT EXISTS clients (id INTEGER PRIMARY KEY, name TEXT UNIQUE)")
        # Locations
        self.conn.execute("CREATE TABLE IF NOT EXISTS locations (id INTEGER PRIMARY KEY, city TEXT, region TEXT)")
        # Projects
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS projects (
                id TEXT PRIMARY KEY, name TEXT, client_id INTEGER, location_id INTEGER,
                pa_number TEXT, bca_status TEXT DEFAULT 'AWAITING', status TEXT DEFAULT 'Pending'
            )
        """)
        
        # Sample data from your Excel[file:142]
        if not pd.read_sql("SELECT COUNT(*) FROM projects", self.conn).iloc[0,0]:
            sample_data = [
                ('P001', 'Dirjanu Supermarket', 1, 1, '7246/22', 'DONE', 'Mobilised'),
                ('P002', 'Hotel All Season', 2, 1, '7298/24', 'AWAITING', 'In Process'),
                ('P003', 'Cutajar Houses', 3, 1, '4893/23', 'NO', 'Pending'),
                ('P004', 'Ex BOV Nadur', 3, 1, '575/24', 'AWAITING', 'In Process')
            ]
            self.conn.executemany("INSERT OR IGNORE INTO projects VALUES(?,?,?,?,?,?,?)", sample_data)
            self.conn.commit()
    
    def get_projects(self):
        return pd.read_sql("""
            SELECT p.*, c.name as client_name, l.city, l.region 
            FROM projects p LEFT JOIN clients c ON p.client_id=c.id 
            LEFT JOIN locations l ON p.location_id=l.id
        """, self.conn)
    
    def update_project(self, project_id, **kwargs):
        fields = ', '.join([f"{k}=?" for k in kwargs])
        self.conn.execute(f"UPDATE projects SET {fields} WHERE id=?", (*kwargs.values(), project_id))
        self.conn.commit()

db = Database()

if 'logged_in' not in st.session_state:
    st.session_state.logged_in = False
    st.session_state.username = None

def landing_page():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <img src="logo.png" width="250">
        <h1 style='color: #1f77b4;'>Estate Hub Malta</h1>
    </div>
    """, unsafe_allow_html=True)
    
    col1, col2 = st.columns(2)
    with col1: username = st.text_input("👤 Username")
    with col2: password = st.text_input("🔑 Password", type="password")
    
    if st.button("🚀 Enter Hub", type="primary"):
        if username in USERS and password == USERS[username]:
            st.session_state.logged_in = True
            st.session_state.username = username
            st.rerun()
        else:
            st.error("❌ Invalid credentials")

def mobilization_module():
    st.header("🚧 Mobilization Tracker[file:142]")
    
    projects_df = db.get_projects()
    
    # Metrics
    col1, col2 = st.columns(2)
    col1.metric("Total Projects", len(projects_df))
    col2.metric("Mobilised", len(projects_df[projects_df['status']=='Mobilised']))
    
    # Progress chart
    progress_data = []
    for _, row in projects_df.iterrows():
        progress = 0
        if row['pa_number']: progress += 40
        if row['bca_status'] != 'AWAITING': progress += 30
        if row['status'] in ['Ready', 'Mobilised']: progress += 30
        progress_data.append({"Project": row['name'][:12], "Progress": progress})
    
    st.subheader("📈 Progress")
    st.bar_chart(pd.DataFrame(progress_data).set_index("Project"))
    
    # Projects table
    st.subheader("📋 Projects")
    st.dataframe(projects_df[['name', 'client_name', 'city', 'pa_number', 'bca_status', 'status']], 
                use_container_width=True)
    
    # Update project
    st.subheader("🔄 Update")
    project_options = {row['id']: f"{row['name']} ({row['city']})" for _, row in projects_df.iterrows()}
    selected_id = st.selectbox("Project", list(project_options.keys()), 
                              format_func=lambda x: project_options[x])
    
    selected = projects_df[projects_df['id']==selected_id].iloc[0]
    col1, col2, col3 = st.columns(3)
    with col1: new_pa = st.text_input("PA #", selected['pa_number'], key=f"pa_{selected_id}")
    with col2: new_bca = st.text_input("BCA", selected['bca_status'], key=f"bca_{selected_id}")
    with col3: new_status = st.selectbox("Status", ["Pending", "In Process", "Ready", "Mobilised"], 
                                        index=["Pending", "In Process", "Ready", "Mobilised"].index(selected['status']),
                                        key=f"status_{selected_id}")
    
    if st.button("💾 Save", type="primary"):
        db.update_project(selected_id, pa_number=new_pa, bca_status=new_bca, status=new_status)
        st.success("✅ Updated!")
        st.rerun()

def hub_dashboard():
    st.markdown("<div style='text-align: center'><img src='logo.png' width='200'><h1>Estate Hub Malta</h1></div>", unsafe_allow_html=True)
    
    tab1, tab2 = st.tabs(["🏠 Home", "🚧 Mobilization"])
    
    with tab1:
        st.success("✅ **Database working!** SQLite tables created.")
        st.info("Projects from New-Sites-to-Open.xlsx[file:142]")
        if st.button("🚪 Logout"):
            st.session_state.logged_in = False
            st.rerun()
    
    with tab2:
        mobilization_module()

def main():
    if st.session_state.logged_in:
        hub_dashboard()
    else:
        landing_page()

if __name__ == "__main__":
    main()
