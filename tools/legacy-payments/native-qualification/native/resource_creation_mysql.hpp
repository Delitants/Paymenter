// Fixed creation/verification queries only, no cleanup/SDK/authority factory.
#pragma once
namespace paymenter_qa { namespace {
class NativeQaCreationDatabase {
    std::unique_ptr<MYSQL,decltype(&mysql_close)> db_;
    const std::function<void()> current_;
    const std::string database_,socket_;
    const pid_t process_=getpid();
    const std::thread::id thread_=std::this_thread::get_id();
    std::string connection_;
    void current()const {guard(getpid()==process_ && std::this_thread::get_id()==thread_,"resource-database-process");current_();}
    void query(const std::string& sql) {current();guard(mysql_real_query(db_.get(),sql.data(),sql.size())==0,"resource-query-incomplete");current();}
    ReadRows select(const std::string& sql,unsigned columns,size_t maximum) {
        query(sql);std::unique_ptr<MYSQL_RES,decltype(&mysql_free_result)> r(mysql_store_result(db_.get()),mysql_free_result);
        guard(bool(r) && mysql_num_fields(r.get())==columns && mysql_num_rows(r.get())<=maximum,"resource-result-bound");
        ReadRows result;MYSQL_ROW row;size_t total=0;
        while((row=mysql_fetch_row(r.get()))!=nullptr){auto lengths=mysql_fetch_lengths(r.get());guard(lengths!=nullptr,"resource-fetch");std::vector<std::string> values;
            for(unsigned i=0;i<columns;++i){guard(row[i] && lengths[i]<=4096,"resource-cell");total+=lengths[i];guard(total<=32768,"resource-result-bound");values.emplace_back(row[i],lengths[i]);}result.push_back(values);}
        guard(mysql_errno(db_.get())==0,"resource-fetch");current();return result;
    }
    void session() {
        auto r=select("SELECT CONNECTION_ID(),@@session.autocommit,DATABASE(),@@socket",4,1);
        guard(r.size()==1 && reader_connection(r[0][0]) && r[0][0]==std::to_string(mysql_thread_id(db_.get())) &&
            (connection_.empty() || r[0][0]==connection_) && r[0][1]=="1" && r[0][2]==database_ && r[0][3]==socket_ &&
            !(db_->server_status&1) && (db_->server_status&2),"resource-session");connection_=r[0][0];
    }
    static std::string names(){return "('paymenter_qa_business_v1','paymenter_qa_journal_v1')";}
public:
    NativeQaCreationDatabase(const std::string& password,const std::string& database,const std::string& socket,std::function<void()> current):
        db_(nullptr,mysql_close),current_(current),database_(database),socket_(socket) {
        current_();guard(!database_.empty() && database_.size()<=64 && database_.find_first_not_of("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_")==std::string::npos &&
            !socket_.empty() && socket_[0]=='/' && socket_.size()<108,"resource-channel");
        db_.reset(mysql_init(nullptr));guard(bool(db_),"resource-client-init");unsigned timeout=2,protocol=MYSQL_PROTOCOL_SOCKET,disabled=0;my_bool reconnect=0;
        for(auto option:{MYSQL_OPT_CONNECT_TIMEOUT,MYSQL_OPT_READ_TIMEOUT,MYSQL_OPT_WRITE_TIMEOUT})guard(mysql_options(db_.get(),option,&timeout)==0,"resource-client-options");
        guard(mysql_options(db_.get(),MYSQL_OPT_PROTOCOL,&protocol)==0 && mysql_options(db_.get(),MYSQL_OPT_LOCAL_INFILE,&disabled)==0 &&
            mysql_options(db_.get(),MYSQL_OPT_RECONNECT,&reconnect)==0,"resource-client-options");
        current();guard(mysql_real_connect(db_.get(),"localhost","root",password.c_str(),database_.c_str(),0,socket_.c_str(),0)!=nullptr,"resource-connect");session();
        query("SET SESSION lock_wait_timeout=2");query("SET SESSION innodb_lock_wait_timeout=2");
        guard(select("SELECT @@session.lock_wait_timeout,@@session.innodb_lock_wait_timeout,@@session.innodb_table_locks",3,1)==ReadRows{{"2","2","1"}},"resource-lock-settings");
    }
    ~NativeQaCreationDatabase(){if(getpid()!=process_)(void)db_.release();}
    std::string connection()const {current();return connection_;}
    void require_absent() {
        session();guard(select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names()+" ORDER BY TABLE_NAME",1,2).empty(),"resource-name-collision");
    }
    void create(size_t index) {guard(index<2,"resource-table");session();query(qa_resource_ddl()[index]);session();}
    std::array<std::string,2> definitions() {
        session();guard(select("SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names()+" ORDER BY TABLE_NAME",3,2)==
            ReadRows{{"paymenter_qa_business_v1","InnoDB","BASE TABLE"},{"paymenter_qa_journal_v1","InnoDB","BASE TABLE"}},"resource-tables");
        guard(select("SELECT 'present' FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN "+names()+" LIMIT 1",1,1).empty(),"resource-trigger");
        guard(select("SELECT 'present' FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names()+" AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN "+names()+") LIMIT 1",1,1).empty(),"resource-foreign-key");
        const auto columns=select("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,COALESCE(COLLATION_NAME,'none'),IS_NULLABLE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names()+" ORDER BY TABLE_NAME,ORDINAL_POSITION LIMIT 9",6,8);
        ReadRows expected;
        for(const auto& table:{"paymenter_qa_business_v1","paymenter_qa_journal_v1"}) {
            expected.push_back({table,"run_id","char(64)","ascii_bin","NO","PRI"});expected.push_back({table,"case_id","char(2)","ascii_bin","NO","PRI"});
            if(std::string(table)=="paymenter_qa_business_v1")expected.push_back({table,"amount","decimal(18,4)","none","NO",""});
            else expected.push_back({table,"business_case_id","char(2)","ascii_bin","NO",""});
            expected.push_back({table,"facts_hash","char(64)","ascii_bin","NO",""});
        }
        guard(columns==expected,"resource-columns");std::array<std::string,2> result;size_t i=0;
        for(const auto& table:{"paymenter_qa_business_v1","paymenter_qa_journal_v1"}){auto r=select(std::string("SHOW CREATE TABLE ")+table,2,1);guard(r.size()==1 && r[0][0]==table && !r[0][1].empty(),"resource-schema");result[i++]=r[0][1];}
        session();return result;
    }
};
}}
